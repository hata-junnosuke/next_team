// アプリ全体の外枠。テーマと画面遷移（go_router）をつなぐ。
// Next.js で言うと app/layout.tsx（全ページに共通の外側）に当たる。
// Step 3 では「応募済み」の状態をここまで持ち上げて持っていたが、Step 4 で Riverpod の
// appliedJobsProvider に移したので、ここは状態を持たない。
//
// MaterialApp.router: https://api.flutter.dev/flutter/material/MaterialApp/MaterialApp.router.html

import 'package:flutter/material.dart';

import 'core/router.dart';
import 'core/theme.dart';

// 状態を持たず、テーマとルーターを組み合わせて返すだけなので Stateless にする。
class WorkerApp extends StatelessWidget {
  // {super.key}: 名前付き引数 key を親クラスにそのまま渡す書き方。
  // key は React の key と同じく、Widget を見分けるための印。
  const WorkerApp({super.key});

  // @override: 親クラスのメソッドを上書きしている印。build は React の return (JSX) に当たり、画面の形を返す。
  @override
  Widget build(BuildContext context) {
    // MaterialApp.router: URL（パス）で画面を決めるルーター版の MaterialApp。
    // 画面の切り替えはすべて router（core/router.dart）に任せる。
    return MaterialApp.router(
      title: 'ワーカーアプリ',
      theme: appTheme,
      routerConfig: router,
    );
  }
}
