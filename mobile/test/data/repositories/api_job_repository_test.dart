// ApiJobRepository のテスト（Step 9。Step 6〜7 のリポジトリの差し替えの復習。Step 8 の自分の応募と取り下げを含む）。
// 偽の ApiClient（test/fakes/fake_api_client.dart）を渡し、
// 「どのパスに・何を送るか」と「応答をどのモデルにするか」を確かめる。
// Rails で言うと、外部 API のクライアントを WebMock で止めて、組み立てた URL と解釈した結果を見るテスト。

import 'package:flutter_test/flutter_test.dart';
import 'package:worker_app/data/repositories/api_job_repository.dart';
import 'package:worker_app/data/services/api_error.dart';
import 'package:worker_app/domain/models/schedule_tab.dart';

import '../../fakes/fake_api_client.dart';
import '../../helpers/json_fixtures.dart';

void main() {
  // late: 「使う前に必ず値を入れる」と約束して、宣言だけ先にする（TypeScript の let x!: T）。
  // 値は setUp で毎回入れ直す。
  late FakeApiClient api;
  late ApiJobRepository repository;

  // setUp: 各テストの前に毎回動く（PHPUnit の setUp、Jest の beforeEach）。
  // テストごとに新しい偽物を作るので、前のテストの記録が混ざらない。
  setUp(() {
    api = FakeApiClient();
    repository = ApiJobRepository(apiClient: api);
  });

  group('募集の一覧', () {
    test('一覧は per_page=100 で送り、日付の指定がなければ date_from と date_to を送らない', () async {
      api.respond(
        'GET',
        '/api/worker/job-postings',
        jobPostingPageJson([jobPostingJson()]),
      );

      // async / await: Future（Promise）を待つ。テストの関数も async にできる（Jest と同じ）。
      final page = await repository.fetchJobPostings();

      expect(page.data, hasLength(1));
      // single: 1 件だけのときにそれを取り出す（2 件以上ならエラー。送ったのが 1 回だけであることも確かめられる）。
      final request = api.sent.single;
      expect(request.query, {'per_page': 100});
    });

    test('日付は YYYY-MM-DD、拠点は location_id で送る', () async {
      api.respond('GET', '/api/worker/job-postings', jobPostingPageJson([]));

      await repository.fetchJobPostings(
        from: DateTime(2026, 9, 6),
        to: DateTime(2026, 9, 13),
        locationId: 10,
      );

      expect(api.sent.single.query, {
        'per_page': 100,
        'date_from': '2026-09-06',
        'date_to': '2026-09-13',
        'location_id': 10,
      });
    });
  });

  group('募集の詳細', () {
    test('詳細が 404 なら null', () async {
      api.respond(
        'GET',
        '/api/worker/job-postings/5',
        const ApiError(statusCode: 404, message: '見つかりません。'),
      );

      expect(await repository.findJobPosting(5), isNull);
    });

    test('404 以外の ApiError はそのまま投げる', () async {
      api.respond(
        'GET',
        '/api/worker/job-postings/5',
        const ApiError(statusCode: 500, message: 'サーバーでエラーが起きました。'),
      );

      // Future が例外で終わることは、expectLater(future, throwsA(...)) で確かめる（await で待つ）。
      await expectLater(
        repository.findJobPosting(5),
        throwsA(isA<ApiError>().having((e) => e.statusCode, 'statusCode', 500)),
      );
    });
  });

  test('応募は本文なしで POST し、data を WorkerApplication にする', () async {
    api.respond('POST', '/api/worker/job-postings/3/applications', {
      'data': workerApplicationJson(id: 77, jobPostingId: 3),
    });

    final application = await repository.apply(3);

    expect(application.id, 77);
    expect(application.jobPosting.id, 3);
    final request = api.sent.single;
    expect(request.method, 'POST');
    // 応募する募集は URL で伝え、本文は送らない（6.11）。
    expect(request.data, isNull);
  });

  test('拠点の応答に data の配列がなければ FormatException', () async {
    api.respond('GET', '/api/worker/locations', {
      'data': {'id': 1, 'name': '東京倉庫'},
    });

    await expectLater(
      repository.fetchLocations(),
      throwsA(isA<FormatException>()),
    );
  });

  group('自分の応募（Step 8）', () {
    test('自分の応募はタブの status と per_page=100 で GET し、data の配列を WorkerApplication の一覧にする', () async {
      api.respond('GET', '/api/worker/applications', {
        'data': [
          workerApplicationJson(id: 1),
          workerApplicationJson(id: 2),
        ],
        'meta': {'current_page': 1, 'last_page': 1, 'per_page': 100, 'total': 2},
      });

      final applications = await repository.fetchMyApplications(
        ScheduleTab.applied,
      );

      // map((a) => a.id): 各要素を変換する（JavaScript の map と同じ）。
      expect(applications.map((a) => a.id), [1, 2]);
      expect(api.sent.single.query, {'status': 'applied', 'per_page': 100});
    });

    test('取り下げは本文なしで /api/worker/applications/{id}/withdraw に POST し、data を WorkerApplication にする', () async {
      api.respond('POST', '/api/worker/applications/9/withdraw', {
        'data': workerApplicationJson(
          id: 9,
          statusValue: 'withdrawn',
          statusLabel: '取り下げ',
          canWithdraw: false,
        ),
      });

      final application = await repository.withdraw(9);

      expect(application.status.label, '取り下げ');
      expect(application.can.withdraw, isFalse);
      final request = api.sent.single;
      expect(request.path, '/api/worker/applications/9/withdraw');
      expect(request.data, isNull);
    });
  });
}
