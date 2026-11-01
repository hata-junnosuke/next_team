// 募集一覧の画面（さがす タブ）。パスは /jobs。
// Next.js で言うと app/jobs/page.tsx に当たる。偽のリポジトリから募集を受け取って並べる。
//
// ListView.builder: https://api.flutter.dev/flutter/widgets/ListView/ListView.builder.html
// 長いリストの作例: https://docs.flutter.dev/cookbook/lists/long-lists

import 'package:flutter/material.dart';

import '../data/fake_job_repository.dart';
import 'job_list_tile.dart';

// 一覧は受け取ったデータを並べるだけで、自分では状態を持たないので Stateless にする。
class JobListScreen extends StatelessWidget {
  const JobListScreen({super.key});

  @override
  Widget build(BuildContext context) {
    // 今は build のたびに偽のリポジトリから受け取る（同期で返るので軽い）。
    // Step 4 で Riverpod の Provider に移し、Step 7 で API の呼び出し（非同期）に替える。
    final jobs = const FakeJobRepository().fetchJobPostings();

    // 下タブは外枠（ScaffoldWithNavBar）の Scaffold が持つ。ここの Scaffold は上のバーと本文だけを持つ。
    return Scaffold(
      appBar: AppBar(title: const Text('さがす')),
      // ListView.builder: 画面に見えている行だけを作るリスト（長くても軽い。React の仮想スクロールに近い）。
      // itemBuilder は行の番号（index）を受け取って、その行の Widget を返す（jobs.map((job) => <JobListTile />) の 1 回分）。
      body: ListView.builder(
        padding: const EdgeInsets.all(8),
        itemCount: jobs.length,
        itemBuilder: (context, index) => JobListTile(job: jobs[index]),
      ),
    );
  }
}
