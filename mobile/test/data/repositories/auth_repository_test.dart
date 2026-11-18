// AuthRepository（ログインの状態の持ち主）のテスト（Step 9。Step 6 の復習）。
// AuthRepository は ChangeNotifier で、ログインの状態が変わると notifyListeners() で聞き手（go_router）に知らせる。
// ここでは偽の ApiClient と、メモリの置き場にした本物の TokenStorage を渡して、
// 「いつトークンを保存し・消し、いつ聞き手に知らせるか」を確かめる。

import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:worker_app/data/repositories/auth_repository.dart';
import 'package:worker_app/data/services/api_error.dart';
import 'package:worker_app/data/services/token_storage.dart';

import '../../fakes/fake_api_client.dart';
import '../../helpers/json_fixtures.dart';

void main() {
  late FakeApiClient api;
  late TokenStorage storage;
  late AuthRepository repository;
  // notifyListeners() が呼ばれた回数。聞き手に知らせたかを数で確かめる。
  late int notified;

  setUp(() {
    FlutterSecureStorage.setMockInitialValues({});
    api = FakeApiClient();
    storage = TokenStorage();
    repository = AuthRepository(apiClient: api, tokenStorage: storage);
    notified = 0;
    // addListener: 変化を聞く関数を登録する（go_router の refreshListenable も同じ仕組みで聞いている）。
    // () => notified++: 呼ばれるたびに 1 足す無名関数（JavaScript の () => notified++ と同じ）。
    repository.addListener(() => notified++);
  });

  test('ログインに成功するとトークンと企業コードを保存し、isLoggedIn が true になって聞き手に知らせる', () async {
    api.respond(
      'POST',
      '/api/worker/login',
      loginResponseJson(token: 'new-token', companyCode: 'ACME0001'),
    );

    final me = await repository.login(
      companyCode: 'acme0001',
      email: 'worker@example.com',
      password: 'password',
    );

    expect(me.name, '山田 太郎');
    expect(repository.isLoggedIn, isTrue);
    expect(notified, 1);
    expect(await storage.readToken(), 'new-token');
    // 入力した値（小文字）ではなく、API が返した企業コードを覚える（docs/01 の 7A.4）。
    expect(await storage.readCompanyCode(), 'ACME0001');
  });

  test('ログインが 422 ならトークンを保存しない', () async {
    api.respond(
      'POST',
      '/api/worker/login',
      const ApiError(
        statusCode: 422,
        message: '企業コード・メールアドレス・パスワードのいずれかが正しくありません。',
      ),
    );

    await expectLater(
      repository.login(companyCode: 'X', email: 'a@example.com', password: 'x'),
      throwsA(isA<ApiError>()),
    );
    expect(repository.isLoggedIn, isFalse);
    expect(notified, 0);
    expect(await storage.readToken(), isNull);
  });

  test('API のログアウトが失敗してもトークンを消す', () async {
    await storage.writeToken('old');
    await repository.restore();
    // 接続できない（statusCode が null）。サーバーのトークンは消せなくても、手元のトークンは消す。
    api.respond(
      'POST',
      '/api/worker/logout',
      const ApiError(statusCode: null, message: 'サーバーに接続できません。'),
    );

    await repository.logout();

    expect(repository.isLoggedIn, isFalse);
    expect(await storage.readToken(), isNull);
  });

  test('expireSession の理由は takeSignOutReason で 1 回だけ取れる', () async {
    await storage.writeToken('old');
    await repository.restore();

    await repository.expireSession('このアカウントは現在利用できません。');

    expect(repository.isLoggedIn, isFalse);
    expect(await storage.readToken(), isNull);
    // 1 回目は理由が取れ、2 回目は null（ログイン画面を開き直しても同じ文言を出し続けない）。
    expect(repository.takeSignOutReason(), 'このアカウントは現在利用できません。');
    expect(repository.takeSignOutReason(), isNull);
  });

  test('理由のない expireSession は『もう一度ログインしてください。』にする', () async {
    await storage.writeToken('old');
    await repository.restore();

    // errors のない 401（トークンが無効）。AuthInterceptor は理由を null で渡す（auth_interceptor_test.dart）。
    // 英語の Unauthenticated. は出さず、決まりの日本語にする（docs/01 の 7A.4 の 401）。
    await repository.expireSession(null);

    expect(repository.takeSignOutReason(), 'もう一度ログインしてください。');
  });

  test('restore はトークンがあればログイン済みにする', () async {
    await storage.writeToken('saved');

    await repository.restore();

    expect(repository.isLoggedIn, isTrue);
    expect(notified, 1);
  });
}
