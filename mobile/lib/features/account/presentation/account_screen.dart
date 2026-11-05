// マイページ タブの画面。パスは /account。
// Step 6 でログイン中のワーカーとログアウトを出す。今は見出しだけの空の画面。
// Next.js で言うと app/account/page.tsx に当たる。

import 'package:flutter/material.dart';

// 状態を持たない空の画面なので Stateless にする（React の、props も state もない関数コンポーネント）。
class AccountScreen extends StatelessWidget {
  const AccountScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('マイページ')),
      body: const Center(child: Text('まだありません')),
    );
  }
}
