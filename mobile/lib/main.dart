// アプリの入口。起動して WorkerApp を画面に出すだけ。
// Next.js で言うと、フレームワークが持っている「起動処理」に当たる（自分では書かない部分）。
// 中身は app.dart に置き、ここには ProviderScope で包むことと、接続先の空の検査以外は足さない。
//
// https://api.flutter.dev/flutter/widgets/runApp.html
// ProviderScope: https://riverpod.dev/ja/docs/introduction/getting_started

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'app.dart';
import 'data/services/api_client.dart';

// void main() はプログラムの入口（Dart では必ず main から始まる）。
void main() {
  // 接続先（--dart-define=API_BASE_URL）を渡し忘れたら、ここで止める（docs/01 の 7A.2）。
  // 止めずに起動すると、最初に API を呼んだ画面で「接続できません」と出て、原因が分かりにくい。
  // StateError は「この状態では続けられない」を表す Dart の標準の例外。
  // flutter run のターミナルに「Unhandled Exception: Bad state: …」と出る。
  if (apiBaseUrl.isEmpty) {
    throw StateError(
      'API_BASE_URL が空です。make mobile-run で起動するか、'
      'flutter run --dart-define=API_BASE_URL=http://localhost:8002 のように接続先を渡してください。',
    );
  }

  // const: コンパイル時に作り終わる値。中身が変わらない Widget に付けると、再描画のたびに作り直さずに済む。
  runApp(
    // ProviderScope = Riverpod の Provider の値をしまっておく入れ物。アプリのいちばん外側に 1 つだけ置く。
    // React で言うと、<QueryClientProvider> や <Provider store={store}> で App 全体を包むのと同じ。
    // 違いは、Provider そのもの（jobListProvider など）は Widget の外にトップレベルの変数として書けること。
    // ProviderScope は「その値の実体をどこに持つか」だけを受け持つ。
    // テストのときは ProviderScope(overrides: [...]) で、偽のリポジトリなどに差し替えられる。
    const ProviderScope(child: WorkerApp()),
  );
}
