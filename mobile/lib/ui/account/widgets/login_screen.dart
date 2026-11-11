// ログイン画面。パスは /login（下タブの外。docs/01 の 7A.3）。
// Next.js で言うと app/login/page.tsx に当たる。
// 今は仮の画面（T5）。フォームは T6 で作る。

import 'package:flutter/material.dart';

// まだ状態を持たない仮の画面なので Stateless にする。
class LoginScreen extends StatelessWidget {
  const LoginScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('ログイン')),
      body: const Center(child: Text('ログイン画面（仮）')),
    );
  }
}
