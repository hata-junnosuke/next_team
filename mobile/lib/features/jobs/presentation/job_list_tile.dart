// 募集一覧の 1 行。日付・時間帯・拠点・職種・時給と「残り○枠」のバッジを出す。
// 残り枠が 0 の行は灰色にし、タップできなくする（詳細に入れないので応募もできない）。
// 応募済みの募集は、バッジを「応募済み」に替える（外枠の AppliedJobs を読む）。
// React で言うと、一覧の map の中で返す <JobListItem job={job} /> コンポーネントに当たる。
//
// Card: https://api.flutter.dev/flutter/material/Card-class.html
// ListTile: https://api.flutter.dev/flutter/material/ListTile-class.html

import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../../../core/format.dart';
import '../domain/job_posting.dart';
import 'applied_jobs_scope.dart';

// 受け取った募集を表示するだけなので Stateless にする（props だけで描く関数コンポーネント）。
class JobListTile extends StatelessWidget {
  const JobListTile({required this.job, super.key});

  final JobPosting job;

  @override
  Widget build(BuildContext context) {
    // Theme.of(context): いちばん近い祖先のテーマを読む（React の useContext(ThemeContext)）。
    final textTheme = Theme.of(context).textTheme;
    // 満員かどうか。Step 7 では API の can.apply（応募できるか）を見るように替える。
    final isFull = job.remaining == 0;
    // 詳細で応募すると AppliedJobs が知らせるので、一覧に戻ったときにはこの行も描き直されている。
    final applied = AppliedJobsScope.of(context).contains(job.id);

    return Card(
      // ListTile = 「上に見出し・下に補足・右に飾り」の 1 行の決まった形。
      child: ListTile(
        // enabled: false にすると、文字が灰色になり、onTap も呼ばれなくなる。
        enabled: !isFull,
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
            // if を [ ] の中に書くと、条件で並べる要素を選べる（collection if。JSX の条件 ? <A/> : <B/>）。
            if (applied)
              const _AppliedBadge()
            else
              _RemainingBadge(remaining: job.remaining),
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

// 「応募済み」のバッジ。表示するだけなので Stateless。
class _AppliedBadge extends StatelessWidget {
  const _AppliedBadge();

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
        '応募済み',
        style: TextStyle(fontSize: 12, color: colors.onTertiaryContainer),
      ),
    );
  }
}
