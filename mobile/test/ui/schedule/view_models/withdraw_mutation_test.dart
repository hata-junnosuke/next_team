// 取り下げの mutation（withdrawApplication）のテスト（Step 9。Step 8 の mutation と、機能をまたぐ invalidate の復習）。
// 形は応募（apply_mutation_test.dart）と同じ。違いは取り直す相手で、取り下げの後は
// よてい（myApplicationsProvider）に加えて、さがす の詳細と一覧（jobDetailProvider・jobListProvider）も取り直す。
// さがす の詳細に「取り下げ」と押せない応募のボタンが出るのは、状態を共有したからではなく、API から取り直したから（docs/01 の 7A.1）。

import 'dart:async';

import 'package:flutter_riverpod/experimental/mutation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:worker_app/data/services/api_error.dart';
import 'package:worker_app/domain/models/date_filter.dart';
import 'package:worker_app/domain/models/schedule_tab.dart';
import 'package:worker_app/domain/models/worker_application.dart';
import 'package:worker_app/ui/jobs/view_models/job_detail_provider.dart';
import 'package:worker_app/ui/jobs/view_models/job_list_provider.dart';
import 'package:worker_app/ui/schedule/view_models/my_applications_provider.dart';
import 'package:worker_app/ui/schedule/view_models/withdraw_mutation.dart';

import '../../../fakes/fake_job_repository.dart';
import '../../../helpers/json_fixtures.dart';
import '../../../helpers/provider_container.dart';

void main() {
  late FakeJobRepository repository;
  late ProviderContainer container;

  // 取り下げる応募（id 9）。その募集は id 3。
  final application = WorkerApplication.fromJson(
    workerApplicationJson(id: 9, jobPostingId: 3),
  );
  final schedule = myApplicationsProvider(ScheduleTab.applied);
  final detail = jobDetailProvider(3);
  final list = jobListProvider(DateFilter.all, null);

  setUp(() async {
    repository = FakeJobRepository();
    container = containerWith(repository);
    container
      ..listen(schedule, (_, _) {})
      ..listen(detail, (_, _) {})
      ..listen(list, (_, _) {})
      ..listen(withdrawMutation(application.id), (_, _) {});
    await container.read(schedule.future);
    await container.read(detail.future);
    await container.read(list.future);
  });

  Future<({int schedule, int detail, int list})> fetchCounts() async {
    await container.read(schedule.future);
    await container.read(detail.future);
    await container.read(list.future);
    return (
      schedule: repository.myApplicationsCalls.length,
      detail: repository.jobPostingCalls.length,
      list: repository.jobPostingsCalls.length,
    );
  }

  test('成功すると よてい と、その募集の詳細と一覧を取り直す', () async {
    await withdrawApplication(container, application);

    expect(repository.withdrawCalls, [9]);
    // 詳細は「応募の id」ではなく「その募集の id（3）」で取り直す。
    expect(repository.jobPostingCalls.last, 3);
    expect(
      container.read(withdrawMutation(application.id)),
      isA<MutationSuccess<Object?>>(),
    );
    expect(await fetchCounts(), (schedule: 2, detail: 2, list: 2));
  });

  test('送信中にもう一度呼んでも 1 回しか送らない', () async {
    repository.withdrawGate = Completer<void>();

    final first = withdrawApplication(container, application);
    await withdrawApplication(container, application);
    repository.withdrawGate!.complete();
    await first;

    expect(repository.withdrawCalls, hasLength(1));
  });

  test('422 は例外を投げ直し、取り直す', () async {
    repository.withdrawError = const ApiError(
      statusCode: 422,
      message: '応募中の応募だけ取り下げられます。',
    );

    await expectLater(
      withdrawApplication(container, application),
      throwsA(isA<ApiError>().having((e) => e.statusCode, 'statusCode', 422)),
    );

    // 管理画面で先に確定された、など。古い表示を取り直す。
    expect(await fetchCounts(), (schedule: 2, detail: 2, list: 2));
  });

  test('接続できないときは例外を投げ直し、取り直さない', () async {
    repository.withdrawError = const ApiError(
      statusCode: null,
      message: 'サーバーに接続できません。',
    );

    await expectLater(
      withdrawApplication(container, application),
      throwsA(isA<ApiError>()),
    );

    expect(await fetchCounts(), (schedule: 1, detail: 1, list: 1));
  });
}
