// よていの一覧の 1 行（Step 8）。勤務日・時間・職種・拠点と、状態のバッジ（API の status.label）を出す。
// 取り下げられる応募（API の can.withdraw が true）だけ、下に「取り下げる」ボタンを出す（T11）。
// 作りは さがす の一覧の 1 行（job_list_tile.dart）と同じ Card + ListTile。
// 送信は詳細の画面の「応募する」と同じ形（確認ダイアログ → mutation → 結果の表示）。
// React で言うと、一覧の map の中で返す <ScheduleItem application={a} /> コンポーネント。
//
// ListTile: https://api.flutter.dev/flutter/material/ListTile-class.html
// スナックバー: https://docs.flutter.dev/cookbook/design/snackbars

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/experimental/mutation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../domain/models/worker_application.dart';
import '../../../utils/format.dart';
import '../view_models/withdraw_mutation.dart';
import 'withdraw_confirm_dialog.dart';

// 取り下げの送信の状態（withdrawMutation）を読むので ConsumerWidget にする
// （T7 では渡された応募を描くだけだったので StatelessWidget だった）。自分では状態を持たない。
class ScheduleListTile extends ConsumerWidget {
  const ScheduleListTile({required this.application, super.key});

  final WorkerApplication application;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final textTheme = Theme.of(context).textTheme;
    // 応募の中の募集の要約（勤務日・時間・職種・拠点）。
    final posting = application.jobPosting;
    // この応募の取り下げの送信の状態。送信中ならボタンを押せなくし、くるくるを出す。
    final isSending =
        ref.watch(withdrawMutation(application.id)) is MutationPending;

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
            // ボタンを出すかは API の can.withdraw だけで決める。状態の値（applied など）は見ない
            // （取り下げられるかは API の業務ルール。7A.4。CLAUDE.md の絶対ルール 1）。
            if (application.can.withdraw)
              Align(
                // Align: 子を親の中のどこに置くか（ここでは右端。CSS の justify-self: end）。
                alignment: Alignment.centerRight,
                child: OutlinedButton(
                  onPressed: isSending ? null : () => _withdraw(context, ref),
                  child: isSending
                      ? const SizedBox.square(
                          dimension: 16,
                          child: CircularProgressIndicator(strokeWidth: 2),
                        )
                      : const Text('取り下げる'),
                ),
              ),
          ],
        ),
        // 状態の文言は API の status.label をそのまま出す（「確定」「応募中」。7A.4 の「状態の文言」）。
        trailing: _StatusBadge(label: application.status.label),
      ),
    );
  }

  // 押したら確認ダイアログを出し、「取り下げる」なら送る。結果はスナックバーで知らせる。
  // 成功すると よてい が取り直されてこの行は一覧から消えるので、行の中に文言を出すのではなく、
  // 画面をまたいで残るスナックバーにする。ScaffoldMessenger は送る前に取っておく
  // （送った後はこの行がもう画面にないことがあり、context が使えないため）。
  Future<void> _withdraw(BuildContext context, WidgetRef ref) async {
    if (!await showWithdrawConfirmDialog(context, application)) return;
    if (!context.mounted) return;
    final messenger = ScaffoldMessenger.of(context);
    try {
      await withdrawApplication(ref, application);
      messenger.showSnackBar(const SnackBar(content: Text('応募を取り下げました')));
    } on Exception catch (e) {
      // 失敗の文言は API の message（ApiError の toString()）か、接続できないときの文言をそのまま出す。
      // 形が違うときだけ決まりの文言にする（詳細の画面の _applyErrorMessage と同じ）。
      messenger.showSnackBar(
        SnackBar(
          content: Text(
            e is FormatException
                ? '応答の形が想定と違います。管理者にお問い合わせください。'
                : '$e',
          ),
        ),
      );
    }
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
