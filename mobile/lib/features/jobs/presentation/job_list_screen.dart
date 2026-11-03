// 募集一覧の画面（さがす タブ）。パスは /jobs。
// Next.js で言うと app/jobs/page.tsx に当たる。上に日付のチップ、下に募集を並べる。
//
// ListView.builder: https://api.flutter.dev/flutter/widgets/ListView/ListView.builder.html
// 長いリストの作例: https://docs.flutter.dev/cookbook/lists/long-lists
// ChoiceChip: https://api.flutter.dev/flutter/material/ChoiceChip-class.html

import 'package:flutter/material.dart';

import '../data/fake_job_repository.dart';
import 'date_filter.dart';
import 'job_list_tile.dart';

// 「どのチップを選んでいるか」を覚えるので Stateful にする。
// 下タブは indexedStack なので、ほかのタブに移って戻っても、選んだチップは残る。
class JobListScreen extends StatefulWidget {
  const JobListScreen({super.key});

  @override
  State<JobListScreen> createState() => _JobListScreenState();
}

class _JobListScreenState extends State<JobListScreen> {
  // 選んでいるチップ。最初は「すべて」。
  DateFilter _filter = DateFilter.all;

  @override
  Widget build(BuildContext context) {
    // 今は build のたびに偽のリポジトリから受け取り、画面の中で絞り込む。
    // Step 4 で Riverpod の Provider に移し、Step 7 で API の呼び出し（date_from / date_to つき）に替える。
    final allJobs = const FakeJobRepository().fetchJobPostings();
    final range = _filter.range(DateUtils.dateOnly(DateTime.now()));
    // where: 条件に合うものだけを残す（JavaScript の filter）。toList() でリストに戻す。
    // range が null（すべて）なら絞り込まない。
    // !isBefore / !isAfter で「from 以上・to 以下」を書く（DateTime には >= がない）。
    final jobs = range == null
        ? allJobs
        : allJobs
              .where(
                (job) =>
                    !job.workDate.isBefore(range.from) &&
                    !job.workDate.isAfter(range.to),
              )
              .toList();

    // 下タブは外枠（ScaffoldWithNavBar）の Scaffold が持つ。ここの Scaffold は上のバーと本文だけを持つ。
    return Scaffold(
      appBar: AppBar(title: const Text('さがす')),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(8, 8, 8, 0),
            // Wrap: 横に並べ、入りきらなければ折り返す（CSS の flex-wrap: wrap）。
            child: Wrap(
              spacing: 8,
              children: [
                // for を [ ] の中に書くと、要素を繰り返して並べられる（collection for。JSX の map に当たる）。
                for (final filter in DateFilter.values)
                  ChoiceChip(
                    label: Text(filter.label),
                    selected: filter == _filter,
                    // チップを押したら、選んだものを state に入れて build をやり直す。
                    // (_) は「引数を受け取るが使わない」印（ここでは選ばれたかどうかの bool）。
                    onSelected: (_) => setState(() => _filter = filter),
                  ),
              ],
            ),
          ),
          // Expanded: Column の残りの高さを全部リストに使う。
          // ListView は高さが決まらないと描けないので、Column の中では Expanded で包む。
          Expanded(
            child: jobs.isEmpty
                ? const Center(child: Text('該当する募集がありません'))
                // ListView.builder: 画面に見えている行だけを作るリスト（長くても軽い。React の仮想スクロールに近い）。
                // itemBuilder は行の番号（index）を受け取って、その行の Widget を返す（jobs.map((job) => <JobListTile />) の 1 回分）。
                : ListView.builder(
                    padding: const EdgeInsets.all(8),
                    itemCount: jobs.length,
                    itemBuilder: (context, index) =>
                        JobListTile(job: jobs[index]),
                  ),
          ),
        ],
      ),
    );
  }
}
