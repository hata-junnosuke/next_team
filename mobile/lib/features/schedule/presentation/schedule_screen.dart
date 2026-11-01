// よてい タブの画面。パスは /schedule。
// 自分の応募の一覧。Step 8 で中身を作る。今は見出しだけの空の画面。
// 書き方は features/jobs/presentation/job_list_screen.dart と同じ（Next.js の app/schedule/page.tsx）。

import 'package:flutter/material.dart';

class ScheduleScreen extends StatelessWidget {
  const ScheduleScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('よてい')),
      body: const Center(child: Text('まだありません')),
    );
  }
}
