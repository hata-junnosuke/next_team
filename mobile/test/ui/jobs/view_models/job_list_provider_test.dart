// jobListProvider（募集の一覧）のテスト（Step 9。Step 4 の Provider と ref、Step 7 の invalidate の復習）。
// jobListProvider は ref.watch(jobRepositoryProvider) でリポジトリを読み、一覧を取ってくる FutureProvider。
// ここでは jobRepositoryProvider を偽物に差し替え、Provider の状態（AsyncValue）の移り変わりと、
// リポジトリに何を渡したかを確かめる。Laravel の Http::fake() でサービスを動かすテストに近い。

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:worker_app/config/dependencies.dart';
import 'package:worker_app/domain/models/date_filter.dart';
import 'package:worker_app/domain/models/job_posting_page.dart';
import 'package:worker_app/ui/jobs/view_models/job_list_provider.dart';

import '../../../fakes/fake_job_repository.dart';
import '../../../helpers/provider_container.dart';

void main() {
  late FakeJobRepository repository;
  late ProviderContainer container;

  setUp(() {
    repository = FakeJobRepository();
    container = containerWith(repository);
  });

  // 引数つきの Provider（family）。jobListProvider(絞り込み, 拠点) ごとに別の値を持つ。
  final allJobs = jobListProvider(DateFilter.all, null);

  test('読み込み中から data になる', () async {
    // listen: 値を購読し続ける（画面の ref.watch の代わり）。購読しないと、使われていない Provider として捨てられる。
    // (_, _) {}: 受け取った値を使わない空の関数（_ は「使わない引数」）。
    container.listen(allJobs, (_, _) {});

    // AsyncValue は「読み込み中 / エラー / データ」の 3 状態（Step 4）。最初は読み込み中。
    expect(container.read(allJobs), isA<AsyncLoading<JobPostingPage>>());

    // .future: 値が届くまで待つ Future（Promise）。
    await container.read(allJobs.future);

    final state = container.read(allJobs);
    expect(state, isA<AsyncData<JobPostingPage>>());
    expect(state.value?.data, hasLength(1));
  });

  test('リポジトリの例外は AsyncError になり、再試行しない', () async {
    repository.jobPostingsError = const FormatException('形が違います');
    // このテストだけ、入れ物の既定を「1 ミリ秒後にすぐ再試行する」にする。
    // Provider に retry の指定がなければ入れ物の既定が使われるので、lib/ の @Riverpod(retry: _noRetry) を
    // 消すと 2 回目が呼ばれて赤になる（入れ物の既定を「再試行しない」にしたままだと、lib/ を消しても緑のままで何も守れない）。
    container = ProviderContainer.test(
      overrides: [jobRepositoryProvider.overrideWithValue(repository)],
      retry: (_, _) => const Duration(milliseconds: 1),
    );
    container.listen(allJobs, (_, _) {});

    await expectLater(
      container.read(allJobs.future),
      throwsA(isA<FormatException>()),
    );
    expect(container.read(allJobs), isA<AsyncError<JobPostingPage>>());

    // jobListProvider は lib/ で再試行を切っている（画面の「再読み込み」で取り直す。Step 7）。
    // 入れ物の既定なら 1 ミリ秒後に 2 回目が来るところを、少し待っても来ないことを確かめる。
    // ここは本物の時間で待つ（短い時間なので、テストが遅くなりすぎない）。
    await Future<void>.delayed(const Duration(milliseconds: 100));
    expect(repository.jobPostingsCalls, hasLength(1));
  });

  test('拠点の id をリポジトリに渡し、すべてのときは日付を渡さない', () async {
    final byLocation = jobListProvider(DateFilter.all, 10);
    container.listen(byLocation, (_, _) {});
    await container.read(byLocation.future);

    final query = repository.jobPostingsCalls.single;
    expect(query.locationId, 10);
    expect(query.from, isNull);
    expect(query.to, isNull);
  });

  test('invalidate すると取り直す', () async {
    container.listen(allJobs, (_, _) {});
    await container.read(allJobs.future);
    expect(repository.jobPostingsCalls, hasLength(1));

    // invalidate: 「この値は古い」と印を付ける。次に読まれたとき（購読中ならすぐ）に作り直す。
    // 応募の後に一覧を新しくするのはこの仕組み（Step 7 の mutation と invalidate）。
    container.invalidate(allJobs);
    await container.read(allJobs.future);

    expect(repository.jobPostingsCalls, hasLength(2));
  });
}
