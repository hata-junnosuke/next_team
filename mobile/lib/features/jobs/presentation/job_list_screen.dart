// 募集一覧の画面（さがす タブ）。パスは /jobs。
// Next.js で言うと app/jobs/page.tsx に当たる。上に日付のチップ、下に募集を並べる。
//
// ListView.builder: https://api.flutter.dev/flutter/widgets/ListView/ListView.builder.html
// 長いリストの作例: https://docs.flutter.dev/cookbook/lists/long-lists
// ChoiceChip: https://api.flutter.dev/flutter/material/ChoiceChip-class.html
// FutureBuilder: https://api.flutter.dev/flutter/widgets/FutureBuilder-class.html

import 'package:flutter/material.dart';

import '../data/fake_job_repository.dart';
import '../domain/job_posting.dart';
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

  // 募集の一覧が届く Future。読み込みは 1 回だけにしたいので、State に持っておく。
  // late: 「初めて使うときに右辺を計算する」印。最初の build で 1 回だけ読み込みが始まり、
  // チップを押して build がやり直されても、同じ Future（読み込み済みの結果）を使い回す。
  // build の中で fetchJobPostings() を呼ぶと、build のたびに読み込み直してしまう（React の useEffect の外で fetch するのと同じ失敗）。
  // Step 4 で Riverpod の Provider に移し、Step 7 で API の呼び出し（date_from / date_to つき）に替える。
  late final Future<List<JobPosting>> _jobsFuture = const FakeJobRepository()
      .fetchJobPostings();

  @override
  Widget build(BuildContext context) {
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
            // FutureBuilder: Future の「待っている / 失敗した / 届いた」に合わせて描き分ける Widget。
            // Future が終わると、自動で builder が呼ばれ直す（React で useEffect + useState で書くことを 1 つにしたもの）。
            child: FutureBuilder<List<JobPosting>>(
              future: _jobsFuture,
              // snapshot: Future の今の様子（data に届いた値、error に例外が入る）。
              builder: (context, snapshot) {
                // 読み込みに失敗したとき（JSON の形が契約と違うなど）。
                // 今は原因を見るために例外の中身も出す。Step 6 で ApiError を作り、利用者向けの文言に替える。
                if (snapshot.hasError) {
                  return Center(
                    child: Text('募集を読み込めませんでした\n${snapshot.error}'),
                  );
                }
                final allJobs = snapshot.data;
                // まだ届いていないとき（読み込み中）。くるくる回る印を出す。
                if (allJobs == null) {
                  return const Center(child: CircularProgressIndicator());
                }

                final jobs = _applyFilter(allJobs);
                if (jobs.isEmpty) {
                  return const Center(child: Text('該当する募集がありません'));
                }
                // ListView.builder: 画面に見えている行だけを作るリスト（長くても軽い。React の仮想スクロールに近い）。
                // itemBuilder は行の番号（index）を受け取って、その行の Widget を返す（jobs.map((job) => <JobListTile />) の 1 回分）。
                return ListView.builder(
                  padding: const EdgeInsets.all(8),
                  itemCount: jobs.length,
                  itemBuilder: (context, index) =>
                      JobListTile(job: jobs[index]),
                );
              },
            ),
          ),
        ],
      ),
    );
  }

  // 選んでいるチップで絞り込む。range が null（すべて）なら絞り込まない。
  // where: 条件に合うものだけを残す（JavaScript の filter）。toList() でリストに戻す。
  // !isBefore / !isAfter で「from 以上・to 以下」を書く（DateTime には >= がない）。
  List<JobPosting> _applyFilter(List<JobPosting> jobs) {
    final range = _filter.range(DateUtils.dateOnly(DateTime.now()));
    if (range == null) {
      return jobs;
    }
    return jobs
        .where(
          (job) =>
              !job.workDate.isBefore(range.from) &&
              !job.workDate.isAfter(range.to),
        )
        .toList();
  }
}
