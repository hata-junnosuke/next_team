// 下タブ（さがす / よてい / おしらせ / マイページ）を持つ、4 画面に共通の外枠。
// Next.js で言うと、タブのナビゲーションを持つ layout.tsx（children の所に各タブの画面が入る）に当たる。
// docs/01 の 7A.1 の core/ に、router.dart から切り出して置いた。
//
// NavigationBar: https://api.flutter.dev/flutter/material/NavigationBar-class.html
// Scaffold: https://api.flutter.dev/flutter/material/Scaffold-class.html

import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

// 自分では状態を持たない。「今どのタブか」は go_router の navigationShell が持っているので Stateless にする。
class ScaffoldWithNavBar extends StatelessWidget {
  // required: 渡し忘れるとコンパイルエラーになる名前付き引数（TypeScript の ? なしのプロパティ）。
  const ScaffoldWithNavBar({required this.navigationShell, super.key});

  // 今のタブと、そのタブの画面（中身）を持つ。
  final StatefulNavigationShell navigationShell;

  @override
  Widget build(BuildContext context) {
    // Scaffold = 画面の骨組み（上のバー・本文・下のバーの置き場所を持つ）。
    return Scaffold(
      // body に今のタブの画面が入る（layout.tsx の {children}）。
      body: navigationShell,
      bottomNavigationBar: NavigationBar(
        selectedIndex: navigationShell.currentIndex,
        // タブを押したら、そのタブ（branch）に切り替える。
        // initialLocation: 今のタブをもう一度押したら、そのタブの最初の画面（例: 詳細 → 一覧）に戻す。
        onDestinationSelected: (index) => navigationShell.goBranch(
          index,
          initialLocation: index == navigationShell.currentIndex,
        ),
        // [ ... ] はリスト（配列）。中身が全部 const なので、リストにも const を付けられる。
        destinations: const [
          NavigationDestination(icon: Icon(Icons.search), label: 'さがす'),
          NavigationDestination(icon: Icon(Icons.event), label: 'よてい'),
          NavigationDestination(
            icon: Icon(Icons.notifications_outlined),
            label: 'おしらせ',
          ),
          NavigationDestination(
            icon: Icon(Icons.person_outline),
            label: 'マイページ',
          ),
        ],
      ),
    );
  }
}
