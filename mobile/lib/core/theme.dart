// アプリ全体の色と文字の設定。
// Next.js で言うと app/globals.css（Tailwind の色の設定）に当たる。
//
// ThemeData: https://api.flutter.dev/flutter/material/ThemeData-class.html
// 色を 1 色から作る ColorScheme.fromSeed: https://api.flutter.dev/flutter/material/ColorScheme/ColorScheme.fromSeed.html

import 'package:flutter/material.dart';

// final: 一度だけ代入できる変数（TypeScript の const）。
// ThemeData(...) は実行時に作られるので const にはできず、final にする。
final ThemeData appTheme = ThemeData(
  // 1 色（seedColor）から、ボタン・背景・文字の色の組み合わせを自動で作る。
  colorScheme: ColorScheme.fromSeed(seedColor: Colors.teal),
);
