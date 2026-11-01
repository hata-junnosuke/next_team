// おしらせ タブの画面。パスは /notices。
// おしらせの一覧。Step 10（プッシュ通知）で中身を作る。今は見出しだけの空の画面。
// 書き方は features/jobs/presentation/job_list_screen.dart と同じ（Next.js の app/notices/page.tsx）。

import 'package:flutter/material.dart';

class NoticesScreen extends StatelessWidget {
  const NoticesScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('おしらせ')),
      body: const Center(child: Text('まだありません')),
    );
  }
}
