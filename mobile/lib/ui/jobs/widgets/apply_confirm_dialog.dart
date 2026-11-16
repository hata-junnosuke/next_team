// 応募の前に出す確認ダイアログ（Step 7）。勤務日・時間・時給・交通費を見せて、「応募する」か「やめる」を選ばせる。
// web で言うと、送信の前に出すモーダル（<dialog> や shadcn の AlertDialog）。window.confirm を見た目つきにしたもの。
// 表示の形は詳細の画面と同じ utils/format.dart を使い、詳細と同じ文字が出るようにする。
//
// showDialog: https://api.flutter.dev/flutter/material/showDialog.html
// AlertDialog: https://api.flutter.dev/flutter/material/AlertDialog-class.html

import 'package:flutter/material.dart';

import '../../../domain/models/job_posting.dart';
import '../../../utils/format.dart';

// ダイアログを開き、「応募する」なら true、「やめる」か外側を押して閉じたら false を返す。
// showDialog<bool> は、ダイアログが Navigator.pop(context, 値) で閉じたときの値を Future で返す
// （JavaScript の await new Promise(resolve => ...) で、ボタンが resolve を呼ぶのに近い）。
// 外側を押して閉じると値なし（null）なので、?? false で「やめる」と同じにする。
Future<bool> showApplyConfirmDialog(BuildContext context, JobPosting job) async {
  final result = await showDialog<bool>(
    context: context,
    builder: (context) => _ApplyConfirmDialog(job: job),
  );
  return result ?? false;
}

// 渡された募集を描くだけなので StatelessWidget。
class _ApplyConfirmDialog extends StatelessWidget {
  const _ApplyConfirmDialog({required this.job});

  final JobPosting job;

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: const Text('この募集に応募しますか？'),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(job.jobTitle, style: Theme.of(context).textTheme.titleMedium),
          const SizedBox(height: 8),
          Text('勤務日: ${formatDate(job.workDate)}'),
          Text('時間: ${formatTimeRange(job.startTime, job.endTime)}'),
          Text('時給: ${formatYen(job.hourlyWage)}'),
          Text('交通費: ${formatYen(job.transportAllowance)}'),
        ],
      ),
      // actions: ダイアログの下に並ぶボタン。右端がいちばん強い操作（Material の決まり）。
      actions: [
        // Navigator.of(context).pop(値): いちばん上の画面（このダイアログ）を閉じ、値を showDialog の呼び手に返す。
        TextButton(
          onPressed: () => Navigator.of(context).pop(false),
          child: const Text('やめる'),
        ),
        FilledButton(
          onPressed: () => Navigator.of(context).pop(true),
          child: const Text('応募する'),
        ),
      ],
    );
  }
}
