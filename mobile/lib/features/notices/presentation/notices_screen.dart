// おしらせ タブの画面。パスは /notices。
// おしらせの一覧。Step 10（プッシュ通知）で中身を作る。今は見出しだけの空の画面。
// Next.js で言うと app/notices/page.tsx に当たる。

import 'package:flutter/material.dart';

// 状態を持たない空の画面なので Stateless にする（React の、props も state もない関数コンポーネント）。
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
