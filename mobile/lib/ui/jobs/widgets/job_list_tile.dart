// 募集一覧の 1 行。日付・時間帯・拠点・職種・時給と「残り○枠」のバッジを出す。
// 満員の行は灰色にし、タップできなくする（詳細に入れないので応募もできない）。
// 応募済みの募集は、バッジを API の my_application.status.label（応募中・確定など）に替える（Step 7）。
// どちらも ApplyStatus.of(job)（API の my_application・can.apply・remaining だけで決める）から選ぶ。
// React で言うと、一覧の map の中で返す <JobListItem job={job} /> コンポーネントに当たる。
//
// Card: https://api.flutter.dev/flutter/material/Card-class.html
// ListTile: https://api.flutter.dev/flutter/material/ListTile-class.html

import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../../../domain/models/apply_status.dart';
import '../../../domain/models/job_posting.dart';
import '../../../utils/format.dart';

// 渡された募集を描くだけなので StatelessWidget にする。
// Step 4〜6 は「応募済みか」をアプリの Provider から読むため ConsumerWidget だった。
// Step 7 からは応募済みかも募集（API の my_application）の中にあるので、Provider を読まなくてよい
// （React で言うと、useContext をやめて props だけで描くコンポーネントに戻した）。
class JobListTile extends StatelessWidget {
  const JobListTile({required this.job, super.key});

  final JobPosting job;

  @override
  Widget build(BuildContext context) {
    // Theme.of(context): いちばん近い祖先のテーマを読む（React の useContext(ThemeContext)）。
    final textTheme = Theme.of(context).textTheme;
    // 詳細で応募すると、一覧（jobListProvider）を取り直すので（T5）、戻ったときには新しい my_application で描かれる。
    final status = ApplyStatus.of(job);

    return Card(
      // ListTile = 「上に見出し・下に補足・右に飾り」の 1 行の決まった形。
      child: ListTile(
        // enabled: false にすると、文字が灰色になり、onTap も呼ばれなくなる。
        // 満員の行だけ押せなくする（応募済みなら満員でも詳細を見られる）。
        // is: 型を確かめる（sealed class の子のどれかを見る。TypeScript の instanceof）。
        enabled: status is! ApplyFull,
        title: Text(job.jobTitle, style: textTheme.titleMedium),
        // Column = 子を縦に並べる（CSS の flex-direction: column）。
        subtitle: Column(
          // crossAxisAlignment.start: 左寄せ（CSS の align-items: flex-start）。
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              '${formatDate(job.workDate)} ${formatTimeRange(job.startTime, job.endTime)}',
            ),
            Text(job.location.name),
          ],
        ),
        // 右端に時給とバッジを縦に並べる。
        trailing: Column(
          // mainAxisAlignment.center: 縦の真ん中に寄せる（CSS の justify-content: center）。
          mainAxisAlignment: MainAxisAlignment.center,
          crossAxisAlignment: CrossAxisAlignment.end,
          children: [
            Text(
              '時給 ${formatYen(job.hourlyWage)}',
              style: textTheme.titleSmall,
            ),
            // SizedBox を高さだけで使うと、すき間になる（CSS の margin の代わり）。
            const SizedBox(height: 4),
            // 状態ごとにバッジを選ぶ。ApplyApplied(:final label) は「ApplyApplied なら label を取り出す」パターン
            // （:label は label: final label の短縮）。|| は「どれかに当てはまれば」で、ほかは残り枠を出す。
            // sealed なので、子を 1 つでも書き漏らすとコンパイルエラーになる。
            switch (status) {
              ApplyApplied(:final label) => _AppliedBadge(label: label),
              ApplyAvailable() || ApplyFull() || ApplyUnavailable() =>
                _RemainingBadge(remaining: job.remaining),
            },
          ],
        ),
        // 押したら詳細へ。context.go は go_router が足したメソッドで、パスで画面を移る（Next.js の router.push）。
        // /jobs/3 は /jobs の子なので、一覧の上に詳細が重なり、戻るボタンが出る。
        onTap: () => context.go('/jobs/${job.id}'),
      ),
    );
  }
}

// 「残り○枠」のバッジ。残り 0 なら灰色にする。
// 一覧の 1 行の中でしか使わないので、同じファイルに _ を付けて置く。表示するだけなので Stateless。
class _RemainingBadge extends StatelessWidget {
  const _RemainingBadge({required this.remaining});

  final int remaining;

  @override
  Widget build(BuildContext context) {
    // colorScheme: theme.dart で seedColor から作った色の組み合わせ。色はここから選び、直に書かない。
    final colors = Theme.of(context).colorScheme;
    final isFull = remaining == 0;

    // Container = 背景色・角丸・余白を付けられる箱（HTML の <div style="...">）。
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
      // BoxDecoration: 箱の見た目（背景色・角丸・枠線）。CSS の background と border-radius。
      decoration: BoxDecoration(
        color: isFull
            ? colors.surfaceContainerHighest
            : colors.primaryContainer,
        borderRadius: BorderRadius.circular(12),
      ),
      child: Text(
        '残り$remaining枠',
        style: TextStyle(
          fontSize: 12,
          color: isFull ? colors.outline : colors.onPrimaryContainer,
        ),
      ),
    );
  }
}

// 「応募済み」のバッジ。文言は API の my_application.status.label（応募中・確定など）。表示するだけなので Stateless。
class _AppliedBadge extends StatelessWidget {
  const _AppliedBadge({required this.label});

  final String label;

  @override
  Widget build(BuildContext context) {
    final colors = Theme.of(context).colorScheme;
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
      decoration: BoxDecoration(
        color: colors.tertiaryContainer,
        borderRadius: BorderRadius.circular(12),
      ),
      child: Text(
        label,
        style: TextStyle(fontSize: 12, color: colors.onTertiaryContainer),
      ),
    );
  }
}
