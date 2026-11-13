// アプリ全体の外枠。テーマと画面遷移（go_router）をつなぐ。
// Next.js で言うと app/layout.tsx（全ページに共通の外側）に当たる。
// Step 3 では「応募済み」の状態をここまで持ち上げて持っていたが、Step 4 で Riverpod の
// appliedJobsProvider に移したので、ここは状態を持たない。
//
// MaterialApp.router: https://api.flutter.dev/flutter/material/MaterialApp/MaterialApp.router.html
// ConsumerWidget: https://riverpod.dev/ja/docs/concepts2/consumers

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'routing/router.dart';
import 'ui/core/themes/theme.dart';

// Step 6 から、ルーターは Provider（routerProvider）になった。それを読むために
// StatelessWidget から ConsumerWidget（Provider を読める StatelessWidget）に変える。
// 自分では状態を持たないことは変わらない。
class WorkerApp extends ConsumerWidget {
  // {super.key}: 名前付き引数 key を親クラスにそのまま渡す書き方。
  // key は React の key と同じく、Widget を見分けるための印。
  const WorkerApp({super.key});

  // @override: 親クラスのメソッドを上書きしている印。build は React の return (JSX) に当たり、画面の形を返す。
  // ConsumerWidget の build は、2 つ目の引数に ref（Provider を読む入口）を受け取る。
  @override
  Widget build(BuildContext context, WidgetRef ref) {
    // MaterialApp.router: URL（パス）で画面を決めるルーター版の MaterialApp。
    // 画面の切り替えはすべて routerProvider（routing/router.dart）に任せる。
    // routerProvider は keepAlive で作り直されないので、この build がやり直されても同じルーターが渡る。
    return MaterialApp.router(
      title: 'ワーカーアプリ',
      theme: appTheme,
      routerConfig: ref.watch(routerProvider),
    );
  }
}
