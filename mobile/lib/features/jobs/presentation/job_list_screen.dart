// 募集一覧の画面（さがす タブ）。パスは /jobs。
// Next.js で言うと app/jobs/page.tsx に当たる。
// 今は見出しだけ。次のコミットで偽データの募集を並べる。
//
// AppBar: https://api.flutter.dev/flutter/material/AppBar-class.html

import 'package:flutter/material.dart';

// 表示するだけで状態を持たないので Stateless にする。
class JobListScreen extends StatelessWidget {
  const JobListScreen({super.key});

  @override
  Widget build(BuildContext context) {
    // 下タブは外枠（ScaffoldWithNavBar）の Scaffold が持つ。ここの Scaffold は上のバーと本文だけを持つ。
    return Scaffold(
      appBar: AppBar(title: const Text('さがす')),
      body: const Center(child: Text('募集の一覧（次のコミットで並べる）')),
    );
  }
}
