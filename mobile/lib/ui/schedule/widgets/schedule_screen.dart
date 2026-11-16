// よてい タブの画面。パスは /schedule。
// 自分の応募を「確定」「応募中」の 2 つのタブに分け、勤務日の順に並べる（Step 8）。
// Next.js で言うと app/schedule/page.tsx に当たる。
// 作りは さがす の一覧（job_list_screen.dart）と同じ: Provider を watch → when で 3 つの状態を描き分け →
// 引っ張って更新（RefreshIndicator）と、失敗したときの「再読み込み」。
//
// RefreshIndicator: https://api.flutter.dev/flutter/material/RefreshIndicator-class.html
// 引っ張って更新: https://riverpod.dev/ja/docs/how_to/pull_to_refresh

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../domain/models/schedule_tab.dart';
import '../../core/ui/error_banner.dart';
import '../view_models/my_applications_provider.dart';
import 'schedule_list_tile.dart';

// 外枠（見出し・タブ・本文）だけを描く。Provider を読むのは中の _ScheduleList なので、ここは StatelessWidget でよい。
// 「どのタブを選んでいるか」は DefaultTabController が持つので、この画面は State を持たなくてよい。
//
// タブの 3 点セット（T8。この機能で新しく出る概念はこれだけ）:
// - DefaultTabController: 選んでいるタブの番号を持ち、下の TabBar と TabBarView に配る係
//   （React で言うと、const [tab, setTab] = useState(0) を Context で子に配る Provider コンポーネント）
// - TabBar: 上に並ぶタブの見出し。押すと選ぶタブが変わる
// - TabBarView: タブごとの中身。左右にスワイプしても切り替わり、TabBar と連動する
// 3 つを同じ数（length）でそろえる。ScheduleTab.values の並びをそのまま使うので、ずれない。
// タブの作例: https://docs.flutter.dev/cookbook/design/tabs
// TabBar: https://api.flutter.dev/flutter/material/TabBar-class.html
class ScheduleScreen extends StatelessWidget {
  const ScheduleScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return DefaultTabController(
      length: ScheduleTab.values.length,
      child: Scaffold(
        appBar: AppBar(
          title: const Text('よてい'),
          // AppBar の bottom に置くと、見出しのすぐ下にタブが並ぶ。
          bottom: TabBar(
            tabs: [for (final tab in ScheduleTab.values) Tab(text: tab.label)],
          ),
        ),
        // タブごとに、そのタブの一覧（myApplicationsProvider(tab)）を読む。
        // 「応募中」は初めて開いたときに取りに行く（Provider は watch されて初めて作られる）。
        body: TabBarView(
          children: [
            for (final tab in ScheduleTab.values) _ScheduleList(tab: tab),
          ],
        ),
      ),
    );
  }
}

// 1 つのタブの分の一覧。どのタブかを受け取り、myApplicationsProvider(tab) を読んで描く。
// Provider を読むので ConsumerWidget。
class _ScheduleList extends ConsumerWidget {
  const _ScheduleList({required this.tab});

  final ScheduleTab tab;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final applicationsAsync = ref.watch(myApplicationsProvider(tab));

    return applicationsAsync.when(
      loading: () => const Center(child: CircularProgressIndicator()),
      // 失敗したとき（API に届かない・500・形が違う）。文言は ErrorBanner（ログイン画面と同じ部品）で出し、
      // 下に「再読み込み」を置く。取り直し中はボタンを押せなくする（一覧の画面の _ErrorView と同じ考え方）。
      error: (error, _) => Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              // 見出しと理由を分けて出す（さがす の一覧・詳細と同じ並び）。理由は ApiError の message か、
              // 接続できないときの文言。形が違うときだけ決まりの文言にする。
              const Text('予定を読み込めませんでした'),
              const SizedBox(height: 8),
              ErrorBanner(
                message: error is FormatException
                    ? '応答の形が想定と違います。管理者にお問い合わせください。'
                    : '$error',
              ),
              const SizedBox(height: 16),
              FilledButton.icon(
                onPressed: applicationsAsync.isLoading
                    ? null
                    : () => ref.invalidate(myApplicationsProvider(tab)),
                // 取り直し中はアイコンをくるくるに替える（さがす の一覧の _ErrorView と同じ）。
                icon: applicationsAsync.isLoading
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
      ),
      data: (applications) => RefreshIndicator(
        onRefresh: () => _refresh(ref),
        child: applications.isEmpty
            // 0 件のときも引っ張れるように、文言も ListView に入れる（job_list_screen.dart と同じ）。
            ? ListView(
                physics: const AlwaysScrollableScrollPhysics(),
                children: [
                  Padding(
                    padding: const EdgeInsets.all(32),
                    child: Center(child: Text('${tab.label}の予定はありません')),
                  ),
                ],
              )
            : ListView.builder(
                physics: const AlwaysScrollableScrollPhysics(),
                padding: const EdgeInsets.all(8),
                itemCount: applications.length,
                itemBuilder: (context, index) =>
                    ScheduleListTile(application: applications[index]),
              ),
      ),
    );
  }

  // 引っ張って更新。捨ててから、取り直しが届くまで待つ（job_list_screen.dart の _refresh と同じ）。
  Future<void> _refresh(WidgetRef ref) async {
    ref.invalidate(myApplicationsProvider(tab));
    try {
      await ref.read(myApplicationsProvider(tab).future);
    } on Exception catch (_) {
      // 失敗の表示は when の error に任せる。
    }
  }
}
