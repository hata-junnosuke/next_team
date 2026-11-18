// go_router の redirect のテスト（Step 9。Step 6 の refreshListenable と redirect の復習）。
// redirect は「画面を出す前に、行き先を変えるか」を決める関数（Next.js の proxy.ts / middleware.ts に当たる）。
// AuthRepository（ChangeNotifier）がログインの状態を変えて notifyListeners() すると、
// go_router は refreshListenable でそれを聞き、redirect を呼び直す。
// だから AuthInterceptor（401）も ViewModel も画面を移さず、ログインの状態を変えるだけでよい。
//
// Widget テスト: testWidgets の中で、アプリ（WorkerApp）を画面なしの仮の画面に描く（pumpWidget）。
// React Testing Library の render(<App />) に当たる。tester.pump() で 1 コマ進める（状態の変化を画面に反映させる）。
// https://docs.flutter.dev/cookbook/testing/widget/introduction

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:worker_app/app.dart';
import 'package:worker_app/config/dependencies.dart';
import 'package:worker_app/data/repositories/auth_repository.dart';
import 'package:worker_app/data/services/token_storage.dart';
import 'package:worker_app/routing/router.dart';
import 'package:worker_app/ui/account/widgets/login_screen.dart';
import 'package:worker_app/ui/jobs/widgets/job_list_screen.dart';

import '../fakes/fake_api_client.dart';
import '../fakes/fake_job_repository.dart';
import '../helpers/json_fixtures.dart';

void main() {
  late FakeApiClient api;
  late TokenStorage storage;
  late AuthRepository auth;
  late ProviderContainer container;

  setUp(() {
    FlutterSecureStorage.setMockInitialValues({});
    api = FakeApiClient()
      ..respond('POST', '/api/worker/login', loginResponseJson());
    storage = TokenStorage();
    auth = AuthRepository(apiClient: api, tokenStorage: storage);
    container = ProviderContainer.test(
      overrides: [
        authRepositoryProvider.overrideWithValue(auth),
        // ログインの後に出る募集一覧の画面のために、募集のリポジトリも偽物にする。
        jobRepositoryProvider.overrideWithValue(FakeJobRepository()),
      ],
      retry: (_, _) => null,
    );
  });

  // アプリを描く。main.dart と同じく、作っておいた入れ物（container）を UncontrolledProviderScope で渡す。
  Future<void> pumpApp(WidgetTester tester) async {
    await tester.pumpWidget(
      UncontrolledProviderScope(container: container, child: const WorkerApp()),
    );
    await tester.pump();
  }

  // 今の場所（URL のパス）。
  String location() => container
      .read(routerProvider)
      .routerDelegate
      .currentConfiguration
      .uri
      .path;

  testWidgets('未ログインで /jobs を開くと /login に移る', (tester) async {
    // 最初の場所は /jobs（initialLocation）。未ログインなので redirect が /login に変える。
    await pumpApp(tester);

    expect(location(), '/login');
    expect(find.byType(LoginScreen), findsOneWidget);
  });

  testWidgets('ログインすると /jobs に移る', (tester) async {
    await pumpApp(tester);

    // 画面のボタンを押す代わりに、ログインの状態の持ち主を直に動かす（画面の操作は login_screen_test.dart で見る）。
    await auth.login(
      companyCode: 'ACME0001',
      email: 'a@example.com',
      password: 'p',
    );
    // notifyListeners() → redirect → 画面の差し替え を 1 コマずつ進める。
    await tester.pump();
    await tester.pump();

    expect(location(), '/jobs');
    expect(find.byType(JobListScreen), findsOneWidget);
  });

  testWidgets('ログイン済みで /login を開くと /jobs に移る', (tester) async {
    await storage.writeToken('saved');
    await auth.restore();
    await pumpApp(tester);
    expect(location(), '/jobs');

    // go: その場所へ移る（Next.js の router.push に近い）。ログイン済みなので redirect が /jobs に戻す。
    container.read(routerProvider).go('/login');
    await tester.pump();

    expect(location(), '/jobs');
  });

  testWidgets('expireSession でログイン画面に戻り、理由が出る', (tester) async {
    await storage.writeToken('saved');
    await auth.restore();
    await pumpApp(tester);
    expect(location(), '/jobs');

    // 401 を受けた AuthInterceptor が呼ぶのと同じ。画面は移さず、状態を変えるだけ。
    await auth.expireSession('このアカウントは現在利用できません。');
    await tester.pump();
    await tester.pump();

    expect(location(), '/login');
    // ログイン画面の上のバナーに理由が出る（LoginViewModel の最初の state）。
    expect(find.text('このアカウントは現在利用できません。'), findsOneWidget);
    // find.byType(Text) などではなく、文言で探す（利用者に見えるもので確かめる。React Testing Library の getByText と同じ考え方）。
    expect(find.widgetWithText(FilledButton, 'ログイン'), findsOneWidget);
  });
}
