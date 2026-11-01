// 画面遷移の定義。どのパスでどの画面を出すかを 1 か所にまとめる。
// Next.js で言うと app/ フォルダの構造（app/jobs/page.tsx など）を、コードで書いたものに当たる。
// ルートの一覧は docs/01 の 7A.3。
//
// go_router: https://pub.dev/packages/go_router
// ルートの書き方: https://pub.dev/documentation/go_router/latest/topics/Configuration-topic.html
// StatefulShellRoute: https://pub.dev/documentation/go_router/latest/go_router/StatefulShellRoute-class.html

import 'package:go_router/go_router.dart';

import '../features/account/presentation/account_screen.dart';
import '../features/jobs/presentation/job_list_screen.dart';
import '../features/notices/presentation/notices_screen.dart';
import '../features/schedule/presentation/schedule_screen.dart';
import 'scaffold_with_nav_bar.dart';

final GoRouter router = GoRouter(
  // 起動したときに開くパス。
  initialLocation: '/jobs',
  routes: [
    // StatefulShellRoute = 下タブのような「共通の外枠 ＋ 中身だけ切り替わる」形を作るルート。
    // Next.js の layout.tsx に近い。違いは、タブ（branch）ごとに画面の履歴と状態を別々に持つこと。
    // indexedStack: 4 つのタブの画面を重ねて全部持っておき、見えるものだけ切り替える。
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
