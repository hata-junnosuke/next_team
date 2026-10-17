<?php

namespace App\Enums;

/**
 * 通知の種類（F-20〜F-23。notification_logs.kind）。
 * dedupe_key の先頭にもこの値を使う（例: application_confirmed:application:15）。
 */
enum NotificationKind: string
{
    case ApplicationConfirmed = 'application_confirmed';
    case PostingCancelled = 'posting_cancelled';
    case ShiftReminder = 'shift_reminder';
    case ExportReady = 'export_ready';

    public function label(): string
    {
        return match ($this) {
            self::ApplicationConfirmed => '確定通知',
            self::PostingCancelled => '中止通知',
            self::ShiftReminder => '前日リマインド',
            self::ExportReady => '出力完了',
        };
    }
}
