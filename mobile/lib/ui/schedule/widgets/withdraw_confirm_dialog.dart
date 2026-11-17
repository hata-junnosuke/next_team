// 取り下げの前に出す確認ダイアログ（Step 8）。職種・勤務日・時間を見せて、「取り下げる」か「やめる」を選ばせる。
// 作りは応募の確認ダイアログ（ui/jobs/widgets/apply_confirm_dialog.dart）と同じ。
//
// showDialog: https://api.flutter.dev/flutter/material/showDialog.html

import 'package:flutter/material.dart';

import '../../../domain/models/worker_application.dart';
import '../../../utils/format.dart';

// ダイアログを開き、「取り下げる」なら true、「やめる」か外側を押して閉じたら false を返す。
Future<bool> showWithdrawConfirmDialog(
  BuildContext context,
  WorkerApplication application,
) async {
  final result = await showDialog<bool>(
    context: context,
    builder: (context) => _WithdrawConfirmDialog(application: application),
  );
  return result ?? false;
}

// 渡された応募を描くだけなので StatelessWidget。
class _WithdrawConfirmDialog extends StatelessWidget {
  const _WithdrawConfirmDialog({required this.application});

  final WorkerApplication application;

  @override
  Widget build(BuildContext context) {
    final posting = application.jobPosting;
    return AlertDialog(
      title: const Text('この応募を取り下げますか？'),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            posting.jobTitle,
            style: Theme.of(context).textTheme.titleMedium,
          ),
          const SizedBox(height: 8),
          Text('勤務日: ${formatDate(posting.workDate)}'),
          Text('時間: ${formatTimeRange(posting.startTime, posting.endTime)}'),
        ],
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(false),
          child: const Text('やめる'),
        ),
        FilledButton(
          onPressed: () => Navigator.of(context).pop(true),
          child: const Text('取り下げる'),
        ),
      ],
    );
  }
}
