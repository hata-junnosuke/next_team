// マイページ タブの画面。パスは /account。
// マイページ。Step 6 でログイン中のワーカーとログアウトを出す。今は見出しだけの空の画面。
// 書き方は features/jobs/presentation/job_list_screen.dart と同じ（Next.js の app/account/page.tsx）。

import 'package:flutter/material.dart';

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
