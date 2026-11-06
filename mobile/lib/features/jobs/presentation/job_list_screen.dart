// 募集一覧の画面（さがす タブ）。パスは /jobs。
// Next.js で言うと app/jobs/page.tsx に当たる。上に日付のチップ、下に募集を並べる。
//
// ListView.builder: https://api.flutter.dev/flutter/widgets/ListView/ListView.builder.html
// 長いリストの作例: https://docs.flutter.dev/cookbook/lists/long-lists
// ChoiceChip: https://api.flutter.dev/flutter/material/ChoiceChip-class.html
// ConsumerWidget / ConsumerStatefulWidget: https://riverpod.dev/ja/docs/concepts2/consumers
// 引っ張って更新: https://riverpod.dev/ja/docs/how_to/pull_to_refresh
// RefreshIndicator: https://api.flutter.dev/flutter/material/RefreshIndicator-class.html

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../domain/job_posting.dart';
import 'date_filter.dart';
import 'job_list_provider.dart';
import 'job_list_tile.dart';

// 「どのチップを選んでいるか」を覚えるので Stateful にし、Provider も読むので Consumer にする。
// ConsumerStatefulWidget = Provider を読める StatefulWidget（State の中で ref が使える）。
// 選んだチップは T7 で Provider に移す。そうしたら State が要らなくなり、ConsumerWidget にできる。
// 下タブは indexedStack なので、ほかのタブに移って戻っても、選んだチップは残る。
class JobListScreen extends ConsumerStatefulWidget {
  const JobListScreen({super.key});

  @override
  ConsumerState<JobListScreen> createState() => _JobListScreenState();
}

// ConsumerState: State と同じだが、ref というフィールドを持つ（build の引数ではなく this.ref で読む）。
class _JobListScreenState extends ConsumerState<JobListScreen> {
  // 選んでいるチップ。最初は「すべて」。
  DateFilter _filter = DateFilter.all;

  @override
  Widget build(BuildContext context) {
    // ref.watch: Provider の値を読み、変わったらこの build をやり直させる（React の useQuery の戻り値に近い）。
    // Step 3 では Future を late で State に持ち、「build のたびに読み込み直さない」ことを自分で守っていた。
    // Provider は取ってきた結果を持っておく（キャッシュ）ので、build が何回呼ばれても取りに行くのは 1 回だけ。
    final jobsAsync = ref.watch(jobListProvider);

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
            // when: AsyncValue の 3 つの状態ごとに、描くものを渡す。
            // 3 つとも名前付きの必須引数なので、どれかを書き忘れるとコンパイルエラーになる
            // （FutureBuilder のときは、読み込み中やエラーの if を書き忘れても通ってしまった）。
            child: jobsAsync.when(
              // 読み込み中。くるくる回る印を出す。
              loading: () => const Center(child: CircularProgressIndicator()),
              // 読み込みに失敗したとき（JSON の形が契約と違うなど）。(error, stackTrace) のうち stackTrace は使わないので _ にする。
              // 今は原因を見るために例外の中身も出す。Step 6 で ApiError を作り、利用者向けの文言に替える。
              error: (error, _) =>
                  Center(child: Text('募集を読み込めませんでした\n$error')),
              // 届いたとき。allJobs は List<JobPosting>（null ではない）。
              // 引っ張って更新している間は、loading ではなく data のまま（前の一覧を出したまま）になる。
              // when は既定で「取り直し中は前の値を見せる」ので、一覧が一瞬消えてくるくるに替わることはない。
              // 取り直し中の印は、RefreshIndicator が上に出すくるくるだけ。
              data: (allJobs) {
                final jobs = _applyFilter(allJobs);
                // RefreshIndicator: 中のリストを下に引っ張ると、上にくるくるを出して onRefresh を呼ぶ。
                // onRefresh が返す Future が終わるまでくるくるを出し続け、終わったら閉じる。
                return RefreshIndicator(
                  onRefresh: _refresh,
                  // 該当なしのときも引っ張れるように、文言も ListView に入れる（RefreshIndicator は
                  // 中身がスクロールできるものでないと反応しない。Center と Text だけでは引っ張れない）。
                  child: jobs.isEmpty
                      ? ListView(
                          // AlwaysScrollableScrollPhysics: 中身が画面より短くても、引っ張れるようにする。
                          physics: const AlwaysScrollableScrollPhysics(),
                          children: const [
                            Padding(
                              padding: EdgeInsets.all(32),
                              child: Center(child: Text('該当する募集がありません')),
                            ),
                          ],
                        )
                      // ListView.builder: 画面に見えている行だけを作るリスト（長くても軽い。React の仮想スクロールに近い）。
                      // itemBuilder は行の番号（index）を受け取って、その行の Widget を返す（jobs.map((job) => <JobListTile />) の 1 回分）。
                      : ListView.builder(
                          physics: const AlwaysScrollableScrollPhysics(),
                          padding: const EdgeInsets.all(8),
                          itemCount: jobs.length,
                          itemBuilder: (context, index) =>
                              JobListTile(job: jobs[index]),
                        ),
                );
              },
            ),
          ),
        ],
      ),
    );
  }

  // 引っ張って更新したときに呼ばれる。
  // ref.invalidate: Provider の今の値を捨て、作り直させる（React の queryClient.invalidateQueries）。
  // 一覧の画面は watch し続けているので、捨てた直後にすぐ作り直され、jobList() がもう一度呼ばれる。
  // invalidate は「捨てて」と頼むだけで、取り直しが終わるのは待たない。RefreshIndicator には
  // 「終わったら閉じて」と伝えたいので、.future（作り直した値が届く Future）を返して、届くまで待たせる。
  // ボタンや引っ張る操作の中なので watch ではなく read（T3 の詳細の _loadJob と同じ考え方）。
  // ref.refresh(jobListProvider.future) と書くと、この 2 行を 1 行にできる（invalidate して読む、の短縮）。
  Future<void> _refresh() async {
    ref.invalidate(jobListProvider);
    await ref.read(jobListProvider.future);
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
