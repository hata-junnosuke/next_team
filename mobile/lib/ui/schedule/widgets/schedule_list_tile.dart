// よていの一覧の 1 行（Step 8）。勤務日・時間・職種・拠点と、状態のバッジ（API の status.label）を出す。
// 作りは さがす の一覧の 1 行（job_list_tile.dart）と同じ Card + ListTile。
// React で言うと、一覧の map の中で返す <ScheduleItem application={a} /> コンポーネント。
//
// ListTile: https://api.flutter.dev/flutter/material/ListTile-class.html

import 'package:flutter/material.dart';

import '../../../domain/models/worker_application.dart';
import '../../../utils/format.dart';

// 渡された応募を描くだけなので StatelessWidget（job_list_tile.dart と同じ）。
class ScheduleListTile extends StatelessWidget {
  const ScheduleListTile({required this.application, super.key});

  final WorkerApplication application;

  @override
  Widget build(BuildContext context) {
    final textTheme = Theme.of(context).textTheme;
    // 応募の中の募集の要約（勤務日・時間・職種・拠点）。
    final posting = application.jobPosting;

    return Card(
      child: ListTile(
        title: Text(posting.jobTitle, style: textTheme.titleMedium),
        subtitle: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              '${formatDate(posting.workDate)} ${formatTimeRange(posting.startTime, posting.endTime)}',
            ),
            Text(posting.location.name),
          ],
        ),
        // 状態の文言は API の status.label をそのまま出す（「確定」「応募中」。7A.4 の「状態の文言」）。
        trailing: _StatusBadge(label: application.status.label),
      ),
    );
  }
}

// 状態のバッジ。表示するだけなので Stateless（job_list_tile.dart の _AppliedBadge と同じ形）。
class _StatusBadge extends StatelessWidget {
  const _StatusBadge({required this.label});

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
