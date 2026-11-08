// 募集一覧の画面（さがす タブ）。パスは /jobs。
// Next.js で言うと app/jobs/page.tsx に当たる。上に日付のチップ、下に募集を並べる。
//
// ListView.builder: https://api.flutter.dev/flutter/widgets/ListView/ListView.builder.html
// 長いリストの作例: https://docs.flutter.dev/cookbook/lists/long-lists
// ChoiceChip: https://api.flutter.dev/flutter/material/ChoiceChip-class.html
// ConsumerWidget / ConsumerStatefulWidget: https://riverpod.dev/ja/docs/concepts2/consumers
// 引っ張って更新: https://riverpod.dev/ja/docs/how_to/pull_to_refresh
// RefreshIndicator: https://api.flutter.dev/flutter/material/RefreshIndicator-class.html
// 引数つきの Provider（family）: https://riverpod.dev/ja/docs/concepts2/family

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../domain/models/date_filter.dart';
import '../view_models/date_filter_provider.dart';
import '../view_models/job_list_provider.dart';
import 'job_list_tile.dart';

// 自分では状態を持たず、選んでいるチップ（selectedDateFilterProvider）と一覧（jobListProvider）を
// Provider から読んで描くだけなので ConsumerWidget にする。
// issue #33 の T6 までは選んだチップを State に持っていたので ConsumerStatefulWidget だった。チップを Provider に移したので
// State が要らなくなった（setState もなくなる）。
// 下タブは indexedStack なので、ほかのタブに移って戻っても、選んだチップは残る（この画面が watch し続けるため）。
class JobListScreen extends ConsumerWidget {
  const JobListScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    // 選んでいるチップ。変わるとこの build がやり直される。
    final filter = ref.watch(selectedDateFilterProvider);
    // jobListProvider(filter): 引数つきの Provider（family）。引数ごとに別の Provider として、別々に値を持つ。
    // チップを「今日」に変えると jobListProvider(DateFilter.today) を読むことになり、まだ値がないので取りに行く
    // （React の useQuery({ queryKey: ['jobs', filter] }) で、キーが変わると取り直すのと同じ）。
    // Step 3 では全部を取ってから画面で絞り込んでいたが、今は絞り込みを Provider とリポジトリに任せる。
    // Step 7 では、この条件がそのまま API の date_from / date_to になる。
    final jobsAsync = ref.watch(jobListProvider(filter));

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
                for (final value in DateFilter.values)
                  ChoiceChip(
                    label: Text(value.label),
                    selected: value == filter,
                    // チップを押したら、選んだものを Provider に入れる。setState は要らない
                    // （Provider の state が変わると、watch しているこの build がやり直される）。
                    // 押した瞬間に 1 回だけなので read。メソッドを呼ぶので .notifier（詳細の画面の「応募する」と同じ）。
                    // (_) は「引数を受け取るが使わない」印（ここでは選ばれたかどうかの bool）。
                    onSelected: (_) => ref
                        .read(selectedDateFilterProvider.notifier)
                        .select(value),
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
              // 読み込み中。くるくる回る印を出す。起動直後と、まだ開いていないチップに切り替えたときに出る。
              loading: () => const Center(child: CircularProgressIndicator()),
              // 読み込みに失敗したとき（偽のリポジトリの 3 回に 1 回のエラー、JSON の形が契約と違うなど）。
              // (error, stackTrace) のうち stackTrace は使わないので _ にする。
              error: (error, _) => _ErrorView(
                error: error,
                // 「再読み込み」を押した後は、取り直しが終わるまで AsyncValue の isLoading が true になる。
                // when は取り直し中も error を出したままにするので（前の値を見せる。issue #33 の T5 と同じ）、
                // ボタンを押せなくしてくるくるを出し、押したことが伝わるようにする。
                isRetrying: jobsAsync.isLoading,
                // ボタンを押した瞬間に 1 回だけなので read の仲間の invalidate（issue #33 の T5 と同じ）。
                // 今のチップの分（jobListProvider(filter)）だけを捨てて取り直す。
                onRetry: () => ref.invalidate(jobListProvider(filter)),
              ),
              // 届いたとき。jobs は List<JobPosting>（null ではない）。絞り込み済み。
              // 引っ張って更新している間は、loading ではなく data のまま（前の一覧を出したまま）になる。
              // when は既定で「取り直し中は前の値を見せる」ので、一覧が一瞬消えてくるくるに替わることはない。
              // 取り直し中の印は、RefreshIndicator が上に出すくるくるだけ。
              data: (jobs) {
                // RefreshIndicator: 中のリストを下に引っ張ると、上にくるくるを出して onRefresh を呼ぶ。
                // onRefresh が返す Future が終わるまでくるくるを出し続け、終わったら閉じる。
                return RefreshIndicator(
                  onRefresh: () => _refresh(ref, filter),
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
  // ConsumerWidget では ref は build の引数なので、ほかのメソッドで使うときは引数で受け取る
  // （ConsumerStatefulWidget のときは State のフィールドだったので、引数が要らなかった）。
  // ref.invalidate: Provider の今の値を捨て、作り直させる（React の queryClient.invalidateQueries）。
  // 一覧の画面は watch し続けているので、捨てた直後にすぐ作り直され、jobList() がもう一度呼ばれる。
  // invalidate は「捨てて」と頼むだけで、取り直しが終わるのは待たない。RefreshIndicator には
  // 「終わったら閉じて」と伝えたいので、.future（作り直した値が届く Future）を返して、届くまで待たせる。
  // ボタンや引っ張る操作の中なので watch ではなく read（詳細の画面の「応募する」と同じ考え方）。
  // ref.refresh(jobListProvider(filter).future) と書くと、この 2 行を 1 行にできる（invalidate して読む、の短縮）。
  Future<void> _refresh(WidgetRef ref, DateFilter filter) async {
    ref.invalidate(jobListProvider(filter));
    // 取り直しが失敗すると、.future も失敗する（例外が飛んでくる）。ここで受け止めないと、
    // RefreshIndicator の中で「誰も受け取らなかった例外」になる。失敗したことは、jobListProvider の値が
    // AsyncError になって when の error が画面に出すので、ここでは受け止めるだけでよい。
    // try / catch は JavaScript と同じ。on Exception catch (_) は「Exception だけを受け取り、中身は使わない」。
    // Exception（通信の失敗など、起こりうる失敗）だけを受け止め、Error（プログラムのバグ）は受け止めずに
    // そのまま表に出す（黙って隠すと、バグに気づけない）。
    try {
      await ref.read(jobListProvider(filter).future);
    } on Exception catch (_) {
      // 画面の表示は when の error に任せる。
    }
  }
}

// 読み込みに失敗したときの表示。文言と「再読み込み」ボタンを出す。
// 押したときにすることは親（一覧の画面）から受け取るので、自分では Provider を読まない → StatelessWidget。
// React で言うと <ErrorView error={e} onRetry={() => refetch()} /> のように、関数を props で渡す形。
class _ErrorView extends StatelessWidget {
  const _ErrorView({
    required this.error,
    required this.isRetrying,
    required this.onRetry,
  });

  final Object error;
  final bool isRetrying;
  // VoidCallback = 引数なし・戻り値なしの関数の型（TypeScript の () => void）。
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          // mainAxisSize.min: Column の高さを中身の分だけにする（既定は使える高さを全部使う）。
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(Icons.error_outline, size: 48),
            const SizedBox(height: 12),
            const Text('募集を読み込めませんでした'),
            const SizedBox(height: 4),
            // 今は原因を見るために例外の中身も出す。Step 6 で ApiError を作り、利用者向けの文言に替える。
            Text(
              '$error',
              textAlign: TextAlign.center,
              style: Theme.of(context).textTheme.bodySmall,
            ),
            const SizedBox(height: 16),
            // FilledButton.icon: アイコンつきの塗りつぶしボタン。取り直し中は押せなくし、アイコンをくるくるに替える。
            FilledButton.icon(
              onPressed: isRetrying ? null : onRetry,
              icon: isRetrying
                  ? const SizedBox.square(
                      dimension: 16,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    )
                  : const Icon(Icons.refresh),
              label: const Text('再読み込み'),
            ),
          ],
        ),
      ),
    );
  }
}
