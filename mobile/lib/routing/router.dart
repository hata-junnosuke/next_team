// 画面遷移の定義。どのパスでどの画面を出すかを 1 か所にまとめる。
// Next.js で言うと app/ フォルダの構造（app/jobs/page.tsx など）を、コードで書いたものに当たる。
// Step 6 から redirect（未ログインならログイン画面へ）も持つ。Next.js の proxy.ts（旧 middleware.ts）に当たる。
// ルートの一覧は docs/01 の 7A.3。
//
// go_router: https://pub.dev/packages/go_router
// ルートの書き方: https://pub.dev/documentation/go_router/latest/topics/Configuration-topic.html
// StatefulShellRoute: https://pub.dev/documentation/go_router/latest/go_router/StatefulShellRoute-class.html
// redirect: https://pub.dev/documentation/go_router/latest/topics/Redirection-topic.html

// kDebugMode（デバッグ実行かどうか）を使うために読み込む。
import 'package:flutter/foundation.dart';
import 'package:go_router/go_router.dart';
import 'package:riverpod_annotation/riverpod_annotation.dart';

import '../config/dependencies.dart';
import '../ui/account/widgets/account_screen.dart';
import '../ui/account/widgets/login_screen.dart';
import '../ui/account/widgets/register_screen.dart';
import '../ui/core/ui/scaffold_with_nav_bar.dart';
import '../ui/jobs/widgets/job_detail_screen.dart';
import '../ui/jobs/widgets/job_list_screen.dart';
import '../ui/notices/widgets/notices_screen.dart';
import '../ui/schedule/widgets/schedule_screen.dart';

part 'router.g.dart';

// ログインしていなくても開けるパス（タブの外の 2 画面）。
const Set<String> _publicPaths = {'/login', '/register'};

// ルーターを Provider にする（Step 5 まではトップレベルの final だった）。
// redirect でログインの状態（authRepositoryProvider）を読むため、Riverpod の中で作る（docs/01 の 7A.1 の routing → config）。
// 生成される名前は routerProvider。app.dart が ref.watch して MaterialApp.router に渡す。
//
// 何を持つか: GoRouter の実体 1 つ。
// いつ作り直されるか: 作り直さない（keepAlive）。ログインの状態が変わっても作り直さず、
// refreshListenable で知らせを受けて redirect だけをやり直す。作り直すと、開いていた画面と戻る履歴が消える。
@Riverpod(keepAlive: true)
GoRouter router(Ref ref) {
  // ref.read ではなく ref.watch: authRepositoryProvider は keepAlive で作り直されないので、実際にはどちらでも同じ。
  // 「この Provider に頼っている」ことを書いておくために watch にする（Riverpod の推奨）。
  final auth = ref.watch(authRepositoryProvider);

  final router = GoRouter(
    // 起動したときに開くパス。未ログインなら、下の redirect が /login に送る。
    initialLocation: '/jobs',
    // refreshListenable: ここに渡したもの（ChangeNotifier）が notifyListeners() したら、redirect をやり直す。
    // AuthRepository がログイン・ログアウトのたびに知らせるので、ログインしたら一覧へ、ログアウトしたらログイン画面へ移る。
    // 画面のコード（ボタンを押した所）で context.go('/jobs') と書かなくてよい（行き先は redirect の 1 か所で決める）。
    refreshListenable: auth,
    // redirect: 画面を移るたびに（と refreshListenable の知らせのたびに）呼ばれ、行き先を変えるかを決める。
    // 文字列を返すとそのパスへ送り直し、null を返すとそのまま進む。
    // Next.js の proxy.ts で NextResponse.redirect する所、Rails の before_action :authenticate に当たる。
    // ここはトークンが手元にあるかだけを見る。トークンが本当に有効かは API の 401 が決める（T10。7A.3）。
    redirect: (context, state) {
      // matchedLocation: 行き先のパス（/jobs/3 など。クエリは含まない）。
      final isPublic = _publicPaths.contains(state.matchedLocation);
      if (!auth.isLoggedIn) {
        // 未ログイン: ログイン画面と初回登録の画面以外は /login へ。
        return isPublic ? null : '/login';
      }
      // ログイン済み: ログイン画面と初回登録の画面に来たら一覧へ（ログインに成功した直後もここを通る）。
      return isPublic ? '/jobs' : null;
    },
    // 画面を移るたびに、行き先のパス（例: going to /jobs/3）をログに出す。デバッグ実行のときだけ。
    // ログは dart:developer の log() で出るので、flutter run のターミナルではなく DevTools の Logging タブに出る。
    // https://docs.flutter.dev/tools/devtools/logging
    // だからパスに秘密を入れない。/login や /register でも、トークン・パスワード・
    // 招待コードはパスやクエリに載せない（rules/common/security.md）。
    debugLogDiagnostics: kDebugMode,
    routes: [
      // タブの外の 2 画面。StatefulShellRoute の外に置くので、下タブが出ない（docs/01 の 7A.3）。
      GoRoute(
        path: '/login',
        builder: (context, state) => const LoginScreen(),
      ),
      GoRoute(
        path: '/register',
        builder: (context, state) => const RegisterScreen(),
      ),
      // StatefulShellRoute = 下タブのような「共通の外枠 ＋ 中身だけ切り替わる」形を作るルート。
      // Next.js の layout.tsx に近い。違いは、タブ（branch）ごとに画面の履歴と状態を別々に持つこと。
      // indexedStack: 一度開いたタブの画面を捨てずに重ねて持っておき、見えるものだけ切り替える。
      // だからタブを移って戻っても、スクロール位置や入力が消えない。
      StatefulShellRoute.indexedStack(
        // => は「1 つの式を返すだけの関数」の短い書き方（JavaScript のアロー関数と同じ）。
        // navigationShell が「今どのタブか」を持っていて、外枠（下タブ）に渡す。
        builder: (context, state, navigationShell) =>
            ScaffoldWithNavBar(navigationShell: navigationShell),
        // branches の並び順が、下タブのボタンの並び順になる。
        branches: [
          StatefulShellBranch(
            routes: [
              GoRoute(
                path: '/jobs',
                builder: (context, state) => const JobListScreen(),
                // 子のルート。パスは親につながって /jobs/:id になる（Next.js の app/jobs/[id]/page.tsx）。
                // 親の中に置くので、詳細は「さがす」タブの中で一覧の上に重なり、戻るボタンで一覧に戻れる。
                routes: [
                  GoRoute(
                    // :id はパスの引数（Next.js の [id]、Rails の routes の :id）。
                    path: ':id',
                    builder: (context, state) {
                      // pathParameters は文字列なので数に変える。
                      // int.tryParse: 数でなければ null を返す（例外にしない）。int? は「int か null」の型。
                      // ! は「ここは null ではない」と言い切る印（ルートの定義上、id は必ずある）。
                      final id = int.tryParse(state.pathParameters['id']!);
                      // key: go_router はページを「パスの形（/jobs/:id）」で見分けるので、/jobs/3 から /jobs/5 に
                      // 移ると同じページ（ルート）が使い回される。id を key にすると、id が変わったら、その下の
                      // 画面の Widget（JobDetailScreen）だけを作り直す（ルートの遷移アニメーションは起きない。
                      // React で key を変えて作り直すのと同じ）。詳細の画面は issue #35 の T4 で State を持たなくなり、
                      // 募集は jobDetailProvider(id) が id ごとに分けて持つので、key がなくても 3 番の結果は出ない。
                      // それでも念のため残す（State を持つ子の Widget を足したときに、前の募集の状態を持ち越さない保険）。
                      return JobDetailScreen(key: ValueKey(id), jobId: id);
                    },
                  ),
                ],
              ),
            ],
          ),
          StatefulShellBranch(
            routes: [
              GoRoute(
                path: '/schedule',
                builder: (context, state) => const ScheduleScreen(),
              ),
            ],
          ),
          StatefulShellBranch(
            routes: [
              GoRoute(
                path: '/notices',
                builder: (context, state) => const NoticesScreen(),
              ),
            ],
          ),
          StatefulShellBranch(
            routes: [
              GoRoute(
                path: '/account',
                builder: (context, state) => const AccountScreen(),
              ),
            ],
          ),
        ],
      ),
    ],
  );

  // ルーターが捨てられるとき（アプリの終了時）に、中で持っている聞き手などを片付ける。
  ref.onDispose(router.dispose);
  return router;
}
