<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * F-23 出力完了の通知（依頼した管理者宛て）。1 つの通知を mail と database（notifications テーブルのアプリ内通知）に出す。
 * Rails なら ActionMailer と通知用のモデルを別々に書くところを、Laravel の Notification は 1 クラスで複数のチャネルに送る。
 *
 * - 送るのは FinalizeReportExport ジョブだけ（その中で claim → notify → markSent）。通知自体はキューに入れない（ShouldQueue を付けない）
 * - チャネルの順は mail → database。メールが失敗すると database の行も作られず、再試行で両方が 1 回ずつ出る
 *   （database → mail の順だと、メールの失敗の再試行でアプリ内通知が 2 件になる）
 * - リンクは管理画面の出力一覧（FRONTEND_URL）。署名付きのダウンロード URL はメールに入れない（転送されると誰でも落とせるうえ、30 分で切れる）
 * - 値は ID・月・行数だけを持つ（モデルを持たない）。月は FormRequest で YYYY-MM に検証済みなので Markdown の記号を含まない
 * https://readouble.com/laravel/13.x/ja/notifications.html
 */
class ReportExportReady extends Notification
{
    public function __construct(
        public readonly int $reportExportId,
        public readonly string $month,
        public readonly int $rowCount,
    ) {}

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("【出力完了】{$this->month} の月次集計 CSV")
            ->greeting('集計出力が完了しました')
            ->line("{$this->month} の月次集計 CSV（{$this->rowCount} 行）の出力が完了しました。")
            ->line('管理画面の出力一覧からダウンロードできます。')
            ->action('出力一覧を開く', rtrim((string) config('app.frontend_url'), '/').'/reports/exports')
            ->salutation(config('app.name'));
    }

    /**
     * database チャネルの notifications.data に入る値
     *
     * @return array{report_export_id: int, month: string, row_count: int}
     */
    public function toArray(User $notifiable): array
    {
        return [
            'report_export_id' => $this->reportExportId,
            'month' => $this->month,
            'row_count' => $this->rowCount,
        ];
    }
}
