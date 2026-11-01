// 募集一覧の 1 行。日付・時間帯・拠点・職種・時給を出す。
// React で言うと、一覧の map の中で返す <JobListItem job={job} /> コンポーネントに当たる。
//
// Card: https://api.flutter.dev/flutter/material/Card-class.html
// ListTile: https://api.flutter.dev/flutter/material/ListTile-class.html

import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../../../core/format.dart';
import '../domain/job_posting.dart';

// 受け取った募集を表示するだけなので Stateless にする（props だけで描く関数コンポーネント）。
class JobListTile extends StatelessWidget {
  const JobListTile({required this.job, super.key});

  final JobPosting job;

  @override
  Widget build(BuildContext context) {
    // Theme.of(context): いちばん近い祖先のテーマを読む（React の useContext(ThemeContext)）。
    final textTheme = Theme.of(context).textTheme;

    return Card(
      // ListTile = 「上に見出し・下に補足・右に飾り」の 1 行の決まった形。
      child: ListTile(
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
        trailing: Text(
          '時給 ${formatYen(job.hourlyWage)}',
          style: textTheme.titleSmall,
        ),
        // 押したら詳細へ。context.go は go_router が足したメソッドで、パスで画面を移る（Next.js の router.push）。
        // /jobs/3 は /jobs の子なので、一覧の上に詳細が重なり、戻るボタンが出る。
        onTap: () => context.go('/jobs/${job.id}'),
      ),
    );
  }
}
