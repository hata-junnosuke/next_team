// 応募の mutation（applyToJobPosting）のテスト（Step 9。Step 7 の mutation と invalidate、Step 8 の機能をまたぐ invalidate の復習）。
// mutation = 「送信中 / 成功 / 失敗」の状態を持つ、1 回の書き込みの入れ物（Riverpod の Mutation）。
// React で言うと TanStack Query の useMutation に当たる。成功したら、関係する Provider を invalidate して API から取り直す
// （onSuccess で queryClient.invalidateQueries を呼ぶのと同じ考え方）。
// https://riverpod.dev/ja/docs/concepts2/mutations
//
// 取り直したかどうかは、画面を開かずに「偽のリポジトリが何回呼ばれたか」で見る。
// そのために、関係する Provider（詳細・一覧・よてい）を listen しておき、最初の 1 回を読み終えてから送信する。

import 'dart:async';

import 'package:flutter_riverpod/experimental/mutation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:worker_app/data/services/api_error.dart';
import 'package:worker_app/domain/models/date_filter.dart';
import 'package:worker_app/domain/models/schedule_tab.dart';
import 'package:worker_app/ui/jobs/view_models/apply_mutation.dart';
import 'package:worker_app/ui/jobs/view_models/job_detail_provider.dart';
import 'package:worker_app/ui/jobs/view_models/job_list_provider.dart';
import 'package:worker_app/ui/schedule/view_models/my_applications_provider.dart';

import '../../../fakes/fake_job_repository.dart';
import '../../../helpers/provider_container.dart';

const _jobId = 1;

void main() {
  late FakeJobRepository repository;
  late ProviderContainer container;

  final detail = jobDetailProvider(_jobId);
  final list = jobListProvider(DateFilter.all, null);
  final schedule = myApplicationsProvider(ScheduleTab.applied);

  setUp(() async {
    repository = FakeJobRepository();
    container = containerWith(repository);
    // 画面が開いている状態のかわりに、関係する Provider と mutation を購読しておく。
    container
      ..listen(detail, (_, _) {})
      ..listen(list, (_, _) {})
      ..listen(schedule, (_, _) {})
      ..listen(applyMutation(_jobId), (_, _) {});
    // 最初の 1 回を読み終える（ここで各リポジトリの呼ばれた回数が 1 になる）。
    await container.read(detail.future);
    await container.read(list.future);
    await container.read(schedule.future);
  });

  // 詳細・一覧・よてい を読み直し、リポジトリが何回呼ばれたかを返す。
  // invalidate されていれば作り直して呼ばれた回数が増え、されていなければ前の値のままで増えない。
  Future<({int detail, int list, int schedule})> fetchCounts() async {
    await container.read(detail.future);
    await container.read(list.future);
    await container.read(schedule.future);
    return (
      detail: repository.jobPostingCalls.length,
      list: repository.jobPostingsCalls.length,
      schedule: repository.myApplicationsCalls.length,
    );
  }

  test('成功すると MutationSuccess になり、詳細と一覧と よてい を取り直す', () async {
    // ProviderContainer は MutationTarget（mutation を動かせる相手）でもあるので、画面の ref の代わりに渡せる。
    await applyToJobPosting(container, _jobId);

    expect(
      container.read(applyMutation(_jobId)),
      isA<MutationSuccess<Object?>>(),
    );
    expect(repository.applyCalls, [_jobId]);
    // 応募の後に よてい も取り直す（Step 8。機能をまたいで状態を共有せず、API から取り直すだけ）。
    expect(await fetchCounts(), (detail: 2, list: 2, schedule: 2));
  });

  test('送信中にもう一度呼んでも 1 回しか送らない', () async {
    repository.applyGate = Completer<void>();

    // 1 回目は門で止まる（await しない = 送信中のまま次へ進む）。
    final first = applyToJobPosting(container, _jobId);
    expect(
      container.read(applyMutation(_jobId)),
      isA<MutationPending<Object?>>(),
    );

    // 送信中の 2 回目（ボタンの連打）。送らずにすぐ戻る。
    await applyToJobPosting(container, _jobId);

    repository.applyGate!.complete();
    await first;
    expect(repository.applyCalls, hasLength(1));
  });

  test('成功の後に呼んでも送らない', () async {
    await applyToJobPosting(container, _jobId);
    await applyToJobPosting(container, _jobId);

    expect(repository.applyCalls, hasLength(1));
  });

  test('422 は MutationError になり、詳細と一覧と よてい を取り直す', () async {
    repository.applyError = const ApiError(
      statusCode: 422,
      message: '定員に達しているため応募できません。',
    );

    // 失敗は投げ直す（画面がスナックバーなどで受けられるように）。
    await expectLater(
      applyToJobPosting(container, _jobId),
      throwsA(isA<ApiError>()),
    );

    expect(
      container.read(applyMutation(_jobId)),
      isA<MutationError<Object?>>(),
    );
    // 422 は「画面の値が古い」（満員になった・もう応募している）ので、取り直して最新の状態を出す（docs/01 の 7A.1）。
    expect(await fetchCounts(), (detail: 2, list: 2, schedule: 2));
  });

  test('接続できないときは取り直さない', () async {
    repository.applyError = const ApiError(
      statusCode: null,
      message: 'サーバーに接続できません。',
    );

    await expectLater(
      applyToJobPosting(container, _jobId),
      throwsA(isA<ApiError>()),
    );

    // 届かなかったので、取り直しても同じく失敗するだけ。今の表示を残す。
    expect(await fetchCounts(), (detail: 1, list: 1, schedule: 1));
  });
}
