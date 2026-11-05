// アプリの入口。起動して WorkerApp を画面に出すだけ。
// Next.js で言うと、フレームワークが持っている「起動処理」に当たる（自分では書かない部分）。
// 中身は app.dart に置き、ここには何も足さない（Step 4 で ProviderScope で包む 1 行だけ増える）。
//
// https://api.flutter.dev/flutter/widgets/runApp.html

import 'package:flutter/material.dart';

import 'app.dart';

// void main() はプログラムの入口（Dart では必ず main から始まる）。
void main() {
  // const: コンパイル時に作り終わる値。中身が変わらない Widget に付けると、再描画のたびに作り直さずに済む。
  runApp(const WorkerApp());
}
