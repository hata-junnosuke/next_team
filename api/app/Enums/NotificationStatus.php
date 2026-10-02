<?php

namespace App\Enums;

/**
 * 通知の送信状態（notification_logs.status）。遷移表はこの canTransitionTo() の 1 箇所だけに置く。
 *
 *   pending → sent / failed
 *   failed  → sent / failed（再実行で送れた / もう一度失敗した。失敗のたびに failed_at と error を書き直す）
 *   sent    → （終端。送信済みはもう送らない）
 *
 * failed の行を NotificationLogService::claim() で取り直しても状態は failed のまま。送れたら markSent() で sent になる。
 */
enum NotificationStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => '送信待ち',
            self::Sent => '送信済み',
            self::Failed => '失敗',
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, match ($this) {
            self::Pending, self::Failed => [self::Sent, self::Failed],
            self::Sent => [],
        }, true);
    }
}
