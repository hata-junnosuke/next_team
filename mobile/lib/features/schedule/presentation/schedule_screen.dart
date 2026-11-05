// よてい タブの画面。パスは /schedule。
// 自分の応募の一覧。Step 8 で中身を作る。今は見出しだけの空の画面。
// Next.js で言うと app/schedule/page.tsx に当たる。

import 'package:flutter/material.dart';

// 状態を持たない空の画面なので Stateless にする（React の、props も state もない関数コンポーネント）。
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
