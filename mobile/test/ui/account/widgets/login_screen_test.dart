// ログイン画面（LoginScreen）の Widget テスト（Step 9。docs/05 の Step 9 の表の「エラーの表示とボタンが押せなくなること」）。
// 画面に文字を入れ、ボタンを押し、出てきた文言を確かめる。React Testing Library の
// render → userEvent.type → userEvent.click → getByText の流れと同じ。
//
// ViewModel の中身（login_view_model_test.dart）とは別に、「state が画面のどこに出るか」をここで守る。
// API は偽の ApiClient、トークンの置き場は setMockInitialValues（ネットワークにも Keychain にも触れない）。

import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:worker_app/config/dependencies.dart';
import 'package:worker_app/data/repositories/auth_repository.dart';
import 'package:worker_app/data/services/api_error.dart';
import 'package:worker_app/data/services/token_storage.dart';
import 'package:worker_app/ui/account/widgets/login_screen.dart';

import '../../../fakes/fake_api_client.dart';
import '../../../helpers/json_fixtures.dart';

const _loginPath = '/api/worker/login';

void main() {
  late FakeApiClient api;
  late TokenStorage storage;

  setUp(() {
    FlutterSecureStorage.setMockInitialValues({});
    api = FakeApiClient();
    storage = TokenStorage();
  });

  // ログイン画面だけを描く（ルーターは使わない。画面の中の表示だけを見るため）。
  // MaterialApp で包むのは、テーマや文字の向きなど、画面が前提にしている土台を用意するため。
  Future<void> pumpLogin(WidgetTester tester) async {
    final auth = AuthRepository(apiClient: api, tokenStorage: storage);
    await tester.pumpWidget(
      ProviderScope(
        overrides: [authRepositoryProvider.overrideWithValue(auth)],
        retry: (_, _) => null,
        child: const MaterialApp(home: LoginScreen()),
      ),
    );
    await tester.pump();
  }

  // ラベル（企業コード など）で入力欄を探して文字を入れる。
  // find.widgetWithText(TextField, '企業コード'): 「企業コード」という文字を中に持つ TextField。
  Future<void> fillForm(WidgetTester tester) async {
    await tester.enterText(find.widgetWithText(TextField, '企業コード'), 'ACME0001');
    await tester.enterText(
      find.widgetWithText(TextField, 'メールアドレス'),
      'worker@example.com',
    );
    await tester.enterText(find.widgetWithText(TextField, 'パスワード'), 'password');
  }

  final loginButton = find.widgetWithText(FilledButton, 'ログイン');

  testWidgets('422 の文言を各欄の下に出す', (tester) async {
    api.respond(
      'POST',
      _loginPath,
      const ApiError(
        statusCode: 422,
        message: '企業コード・メールアドレス・パスワードのいずれかが正しくありません。',
        errors: {
          'company_code': ['企業コードを入力してください。'],
          'email': ['企業コード・メールアドレス・パスワードのいずれかが正しくありません。'],
        },
      ),
    );
    await pumpLogin(tester);
    await fillForm(tester);

    // tap: ボタンを押す。pump で押した後の画面を描き直す。
    await tester.tap(loginButton);
    await tester.pump();

    // TextField の errorText は欄の下に赤い文字で出る。文言で探せば、どこに出たかまで確かめられる。
    expect(find.text('企業コードを入力してください。'), findsOneWidget);
    expect(find.text('企業コード・メールアドレス・パスワードのいずれかが正しくありません。'), findsOneWidget);
  });

  testWidgets('送信中はボタンが押せず、くるくるが出る', (tester) async {
    api
      ..gate = Completer<void>()
      ..respond('POST', _loginPath, loginResponseJson());
    await pumpLogin(tester);
    await fillForm(tester);

    await tester.tap(loginButton);
    await tester.pump();

    // くるくる（CircularProgressIndicator）が出て、ボタンの onPressed が null（押せない）。
    // tester.widget<型>(探し方): 見つけた Widget を取り出して、その設定（onPressed）を読む。
    expect(find.byType(CircularProgressIndicator), findsOneWidget);
    final button = tester.widget<FilledButton>(find.byType(FilledButton));
    expect(button.onPressed, isNull);

    // 押せないボタンをもう一度押しても、送るのは 1 回だけ。
    await tester.tap(find.byType(FilledButton));
    await tester.pump();
    expect(api.sent.where((r) => r.path == _loginPath), hasLength(1));

    // 門を開けて送信を終わらせる（テストの後にタイマーや Future を残さない）。
    api.gate!.complete();
    await tester.pump();
  });

  testWidgets('サーバの message をバナーに出す', (tester) async {
    api.respond(
      'POST',
      _loginPath,
      const ApiError(
        statusCode: 429,
        message: 'ログインの試行が多すぎます。しばらくしてからお試しください。',
      ),
    );
    await pumpLogin(tester);
    await fillForm(tester);

    await tester.tap(loginButton);
    await tester.pump();

    expect(find.text('ログインの試行が多すぎます。しばらくしてからお試しください。'), findsOneWidget);
  });

  testWidgets('前回の企業コードを入れておく', (tester) async {
    await storage.writeCompanyCode('ACME0001');

    await pumpLogin(tester);

    // TextField の中身は controller.text に入っている。
    final field = tester.widget<TextField>(
      find.widgetWithText(TextField, '企業コード'),
    );
    expect(field.controller?.text, 'ACME0001');
  });
}
