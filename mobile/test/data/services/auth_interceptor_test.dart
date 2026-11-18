// AuthInterceptor のテスト（Step 9。Step 6 の 401 の流れの復習）。
// AuthInterceptor は dio の「送る前・失敗した後に毎回はさむ処理」。
// - 送る前: API と同じ送り先にだけ Authorization: Bearer <トークン> を付ける
// - 401 を受けたら: onUnauthorized（アプリでは AuthRepository.expireSession）を呼ぶ。画面は移さない（行き先は redirect が決める）
// Next.js で言うと、lib/api.ts の中で Cookie を付け、401 ならログインへ戻す所。Rails なら Faraday のミドルウェア。
//
// 本物の ApiClient に本物の AuthInterceptor を入れ、通信だけを偽のアダプタにする（issue #50 の Q2）。
// トークンの置き場は setMockInitialValues のメモリ（Keychain に触れない）。

import 'package:dio/dio.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:worker_app/data/services/api_client.dart';
import 'package:worker_app/data/services/api_error.dart';
import 'package:worker_app/data/services/auth_interceptor.dart';
import 'package:worker_app/data/services/token_storage.dart';

import '../../fakes/fake_http_adapter.dart';

const _apiBaseUrl = 'http://api.test';

void main() {
  late TokenStorage storage;
  // onUnauthorized に渡された理由の記録。呼ばれなければ空のまま。
  late List<String?> unauthorizedReasons;

  setUp(() {
    FlutterSecureStorage.setMockInitialValues({});
    storage = TokenStorage();
    unauthorizedReasons = [];
  });

  // 本物の ApiClient + 本物の AuthInterceptor + 偽のアダプタ。
  ApiClient clientWith(FakeHttpAdapter adapter) => ApiClient(
    baseUrl: _apiBaseUrl,
    interceptors: [
      AuthInterceptor(
        apiBaseUrl: _apiBaseUrl,
        tokenStorage: storage,
        // async の無名関数。呼ばれた理由を記録するだけ（本物は AuthRepository.expireSession）。
        onUnauthorized: (reason) async => unauthorizedReasons.add(reason),
      ),
    ],
    httpClientAdapter: adapter,
  );

  // 401 の応答。errors のキーがあるのは「無効にされたワーカー」（5.3。errors は空の {}）、ないのは「トークンが無効」。
  // 契約どおり errors を空にしておく。「errors が空でないか」で判定するように変えると、このテストが赤になる。
  const disabled = FakeResponse(401, {
    'message': 'このアカウントは現在利用できません。管理者にお問い合わせください。',
    'errors': <String, dynamic>{},
  });
  const unauthenticated = FakeResponse(401, {'message': 'Unauthenticated.'});

  test('API と同じ送り先にだけ Authorization を付ける', () async {
    await storage.writeToken('secret-token');
    final adapter = FakeHttpAdapter((_) => const FakeResponse(200, {}));
    final client = clientWith(adapter);

    await client.get('/api/worker/me');
    // 完全な URL を渡すと baseUrl は使われない。ほかの送り先にトークンを漏らさないことを確かめる。
    await client.get('https://other.example.com/collect');

    // [0] [1]: 一覧の 0 番目・1 番目（JavaScript の配列と同じ）。
    expect(adapter.requests[0].headers['Authorization'], 'Bearer secret-token');
    expect(adapter.requests[1].headers.containsKey('Authorization'), isFalse);
  });

  test('トークンがなければ付けない', () async {
    final adapter = FakeHttpAdapter((_) => const FakeResponse(200, {}));

    await clientWith(adapter).get('/api/worker/job-postings');

    expect(
      adapter.requests.single.headers.containsKey('Authorization'),
      isFalse,
    );
  });

  test('401 で onUnauthorized を呼び、errors があれば message を理由にする', () async {
    await storage.writeToken('secret-token');
    final client = clientWith(FakeHttpAdapter((_) => disabled));

    // インターセプタは例外を止めない（handler.next）。呼んだ側には ApiError が届く。
    await expectLater(client.get('/api/worker/me'), throwsA(isA<ApiError>()));

    expect(unauthorizedReasons, ['このアカウントは現在利用できません。管理者にお問い合わせください。']);
  });

  test('errors がない 401 は理由が null', () async {
    await storage.writeToken('secret-token');
    final client = clientWith(FakeHttpAdapter((_) => unauthenticated));

    await expectLater(client.get('/api/worker/me'), throwsA(isA<ApiError>()));

    // null なら AuthRepository が「もう一度ログインしてください。」にする（英語の Unauthenticated. は出さない）。
    expect(unauthorizedReasons, [null]);
  });

  test('古いトークンで送った 401 では呼ばない', () async {
    await storage.writeToken('old-token');
    // 送った後、応答が返る前に、別の画面でログインし直して新しいトークンになった、という場面を作る。
    final client = clientWith(
      FakeHttpAdapter((RequestOptions options) async {
        await storage.writeToken('new-token');
        return unauthenticated;
      }),
    );

    await expectLater(client.get('/api/worker/me'), throwsA(isA<ApiError>()));

    // 古いトークンの 401 で、ログインし直した新しい状態を消さない。
    expect(unauthorizedReasons, isEmpty);
    expect(await storage.readToken(), 'new-token');
  });
}
