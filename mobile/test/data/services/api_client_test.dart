// data/services/api_client.dart のテスト（Step 9）。
// - parseModel: 生成した fromJson の失敗を FormatException にそろえる関数（Step 7 の復習）
// - ApiClient: dio の失敗（DioException）を ApiError に変える所（Step 6 の復習）。
//   偽のアダプタ（test/fakes/fake_http_adapter.dart）で、通信だけを偽物にして確かめる
//
// parseModel は「どのリポジトリも通る 1 か所」なので、ここを守れば画面の on FormatException が効き続ける。
// web で言うと、zod の parse が落ちたときに決まった例外になることを確かめるテスト。

import 'package:flutter_test/flutter_test.dart';
import 'package:worker_app/data/services/api_client.dart';
import 'package:worker_app/data/services/api_error.dart';
import 'package:worker_app/domain/models/job_posting.dart';

import '../../fakes/fake_http_adapter.dart';
import '../../helpers/json_fixtures.dart';

// 偽のアダプタを差し込んだ ApiClient を作る（baseUrl はどこにも届かない値でよい）。
ApiClient clientWith(FakeHttpAdapter adapter) =>
    ApiClient(baseUrl: 'http://api.test', httpClientAdapter: adapter);

void main() {
  group('parseModel', () {
    test('Map でなければ FormatException', () {
      // 配列（List）や文字列が来たら、fromJson に渡す前に止める。
      expect(
        () => parseModel(<Object?>[1, 2], JobPosting.fromJson),
        throwsA(isA<FormatException>()),
      );
      expect(
        () => parseModel('oops', JobPosting.fromJson),
        throwsA(isA<FormatException>()),
      );
    });

    test('形が違えば FormatException にそろえ、メッセージにキー名が入る', () {
      // remaining が数でなく文字列。生成した fromJson は CheckedFromJsonException を投げ、
      // parseModel がそれを FormatException に変える（クラス名とキーだけで、値は入れない）。
      final json = jobPostingJson()..['remaining'] = 'たくさん';

      expect(
        () => parseModel(json, JobPosting.fromJson),
        throwsA(
          isA<FormatException>()
              .having((e) => e.message, 'message', contains('remaining'))
              .having((e) => e.message, 'message', contains('JobPosting'))
              // 値（たくさん）は例外の文言に入れない（個人情報を例外に残さない方針）。
              .having((e) => e.message, 'message', isNot(contains('たくさん'))),
        ),
      );
    });

    test('形が合えばモデルを返す', () {
      final job = parseModel(jobPostingJson(id: 3), JobPosting.fromJson);

      expect(job.id, 3);
    });
  });

  group('ApiClient', () {
    test('422 は statusCode と message と errors を持つ ApiError になる', () async {
      final client = clientWith(
        FakeHttpAdapter(
          (_) => const FakeResponse(422, {
            'message': 'メールアドレスを入力してください。',
            'errors': {
              'email': ['メールアドレスを入力してください。'],
            },
          }),
        ),
      );

      await expectLater(
        client.post('/api/worker/login', data: {'email': ''}),
        throwsA(
          isA<ApiError>()
              .having((e) => e.statusCode, 'statusCode', 422)
              .having((e) => e.message, 'message', 'メールアドレスを入力してください。')
              .having(
                (e) => e.firstErrorOf('email'),
                'errors の email',
                'メールアドレスを入力してください。',
              ),
        ),
      );
    });

    test('接続できないときは決まりの文言の ApiError になり statusCode は null', () async {
      final client = clientWith(FakeHttpAdapter.unreachable());

      await expectLater(
        client.get('/api/worker/me'),
        throwsA(
          isA<ApiError>()
              .having((e) => e.statusCode, 'statusCode', isNull)
              .having((e) => e.message, 'message', contains('サーバーに接続できません')),
        ),
      );
    });

    test('JSON でない 500 は状態コード入りの文言になる', () async {
      // Laravel が落ちて HTML のエラー画面を返した、などの場合。HTML の中身は画面に出さない。
      final client = clientWith(
        FakeHttpAdapter(
          (_) => const FakeResponse(500, '<html>Server Error</html>'),
        ),
      );

      await expectLater(
        client.get('/api/worker/me'),
        throwsA(
          isA<ApiError>()
              .having((e) => e.statusCode, 'statusCode', 500)
              .having((e) => e.message, 'message', contains('状態コード 500'))
              .having((e) => e.message, 'message', isNot(contains('html'))),
        ),
      );
    });

    test('GET の応答が JSON のオブジェクトでなければ FormatException', () async {
      // 200 なのに配列が返ってきた（契約のずれ）。黙って使わずに止める（docs/01 の 7A.4）。
      final client = clientWith(
        FakeHttpAdapter((_) => const FakeResponse(200, [1, 2, 3])),
      );

      await expectLater(
        client.get('/api/worker/locations'),
        throwsA(isA<FormatException>()),
      );
    });
  });
}
