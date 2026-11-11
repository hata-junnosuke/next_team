// 初回登録の画面。パスは /register（下タブの外。docs/01 の 7A.3）。
// 招待コードでパスワードを決める画面（T12 で作る）。今は仮の画面。

import 'package:flutter/material.dart';

class RegisterScreen extends StatelessWidget {
  const RegisterScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('初回登録')),
      body: const Center(child: Text('初回登録の画面（仮）')),
    );
  }
}
