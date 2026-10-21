<?php

namespace App\Services;

use App\Enums\NotificationKind;
use App\Enums\NotificationStatus;
use App\Models\NotificationLog;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

/**
 * 通知の記録と冪等性（同じ通知を二重に送らない）。
 *
 * メールを送るジョブは必ず次の順に呼ぶ（async-rules）:
 *   $log = claim(...) → null なら return（送信済み）→ 送信 → markSent($log)
 *   例外 3 回か期限切れで打ち切った後、ジョブの failed() から markFailedByKey(dedupeKey(...), $e)。
 *   行がなければ（claim の前に打ち切られた。例: 流量制限で戻され続けて期限切れ）、まだ送るべき対象なら recordFailure(...)
 *
 * 「送信 → 記録」の順なので、送信の直後・記録の前に落ちると再試行でもう 1 通送る（at-least-once）。
 * 企業は BelongsToCompany が CurrentCompany::id() で埋める（ジョブには Context で引き継がれる）。
 */
class NotificationLogService
{
    /**
     * 通知 1 通を表すキー。notification_logs.dedupe_key の UNIQUE がこのキーで二重送信を止める。
     * 例: application_confirmed:application:15 / export_ready:report_export:3（*_type は morph map の短い名前）
     *
     * - 中止通知の対象は募集ではなく応募（posting_cancelled:application:{応募ID}）。宛先のワーカーごとに 1 通にするため
     * - キーは全社で一意（企業ID を含めない）。ID は全社の通し番号なので衝突しない。企業ごとにすると、
     *   企業を取り違えたジョブが別の企業の行として 2 通目を送れてしまう
     */
    public static function dedupeKey(NotificationKind $kind, Model $subject): string
    {
        return "{$kind->value}:{$subject->getMorphClass()}:{$subject->getKey()}";
    }

    /**
     * 送ってよいなら記録の行を、送信済みなら null を返す。
     *
     * - 宛先か対象が今の企業の保存済みの行だと確かめられない → LogicException（行を作らない。企業を取り違えたジョブを止める）
     * - 宛先のアドレスは宛先のモデルの email から決める（呼び出し側が別のアドレスを渡せないように）。なければ LogicException
     * - 行がない          → pending で作って返す
     * - 行があって sent    → null（送らない）
     * - 行があって pending / failed → その行を返す（送り直してよい）。宛先は今のメールアドレスにする
     *
     * 2 つのワーカーが同時に「行がない」と判断しても、firstOrCreate は INSERT が UNIQUE 違反になったら
     * 既存の行を読み直す（createOrFirst）ので、行は 1 つにまとまる。
     * https://readouble.com/laravel/13.x/ja/eloquent.html#retrieving-or-creating-models
     */
    public function claim(NotificationKind $kind, Model $recipient, Model $subject): ?NotificationLog
    {
        self::assertSameCompany($recipient, '宛先');
        self::assertSameCompany($subject, '対象');

        $email = $recipient->getAttribute('email');
        if (! is_string($email) || $email === '') {
            throw new LogicException("通知の宛先（{$recipient->getMorphClass()} {$recipient->getKey()}）のメールアドレスが読み込まれていません。");
        }

        $log = NotificationLog::query()->firstOrCreate(
            ['dedupe_key' => self::dedupeKey($kind, $subject)],
            [
                'kind' => $kind,
                'recipient_type' => $recipient->getMorphClass(),
                'recipient_id' => $recipient->getKey(),
                'subject_type' => $subject->getMorphClass(),
                'subject_id' => $subject->getKey(),
                'recipient_email' => $email,
            ],
        );

        if ($log->status === NotificationStatus::Sent) {
            return null;
        }

        // 失敗の後にワーカーがメールアドレスを直していたら、直した宛先に送る（recipient_email は「送った時点の宛先」）
        if ($log->recipient_email !== $email) {
            $log->update(['recipient_email' => $email]);
        }

        return $log;
    }

    /**
     * 送信済みにする。別のワーカーが先に送信済みにしていたら何もしない（sent_at は最初の値のまま）。
     * at-least-once の範囲の出来事なので、例外にして失敗の数を水増ししない。
     */
    public function markSent(NotificationLog $log): void
    {
        DB::transaction(function () use ($log) {
            $log->refresh();

            if ($log->status === NotificationStatus::Sent) {
                return;
            }

            $log->transitionTo(NotificationStatus::Sent, ['sent_at' => now()]);
        });
    }

    /**
     * 失敗を業務の記録に残す（「誰への何の通知が届いていないか」。F-24 の画面が読む）。
     * error には例外のクラス名と固定の文言だけを残す。生のメッセージには SQL のバインド値や宛先・認証情報が入りうるので、
     * 画面に出る列には書かない（生の内容は failed_jobs とログが持つ）。
     * 送信済みの行は失敗に戻さない（届いた通知を「届いていない」と表示しない）。
     */
    public function markFailed(NotificationLog $log, ?Throwable $e): void
    {
        DB::transaction(function () use ($log, $e) {
            $log->refresh();

            if ($log->status === NotificationStatus::Sent) {
                return;
            }

            $log->transitionTo(NotificationStatus::Failed, [
                'failed_at' => now(),
                'error' => self::errorSummary($e),
            ]);
        });
    }

    /**
     * ジョブの failed() から呼ぶ。failed() はジョブの外で呼ばれ、claim した行を持っていないのでキーで探す。
     * 行があれば failed にして true（送信済みなら変えないが、行はあるので true）。行がなければ何もせず false。
     * false のときは、呼び出し側が「まだ送るべき対象か」を確かめてから recordFailure() で記録を作る。
     */
    public function markFailedByKey(string $dedupeKey, ?Throwable $e): bool
    {
        $log = NotificationLog::query()->where('dedupe_key', $dedupeKey)->first();

        if ($log === null) {
            return false;
        }

        $this->markFailed($log, $e);

        return true;
    }

    /**
     * claim の前に打ち切られた通知（例: 流量制限で戻され続けて retryUntil を過ぎた）の「届いていない」記録を作る。
     * claim と同じ検査（宛先・対象が今の企業の保存済みの行か）を通し、claim が null（送信済み）なら何もしない。
     * 行が返れば failed にする。ジョブの failed() から、まだ送るべき対象だと確かめた後に呼ぶ。
     */
    public function recordFailure(NotificationKind $kind, Model $recipient, Model $subject, ?Throwable $e): void
    {
        $log = $this->claim($kind, $recipient, $subject);

        if ($log !== null) {
            $this->markFailed($log, $e);
        }
    }

    /**
     * 例: TransportException: メールの送信に失敗しました。
     */
    private static function errorSummary(?Throwable $e): string
    {
        if ($e === null) {
            return '処理に失敗しました。（例外の情報なし）';
        }

        $message = match (true) {
            $e instanceof QueryException => 'データベースの処理に失敗しました。',
            $e instanceof TransportExceptionInterface => 'メールの送信に失敗しました。',
            default => '処理に失敗しました。',
        };

        return class_basename($e).': '.$message;
    }

    /**
     * 宛先・対象は「今の企業（CurrentCompany::id()）の、保存済みの行」でなければならない。確かめられなければ閉じた側に倒す:
     * - 未保存（exists が false か主キーが null）→ 行を指せない
     * - company_id が null（select で列を省いて読んだなど）→ どの企業の行か分からない
     * - company_id が他社 → 企業を取り違えたジョブ
     * どれも呼び出し側のバグなので LogicException。morph map の 4 モデルはすべて company_id を持つ。
     */
    private static function assertSameCompany(Model $model, string $role): void
    {
        $label = "通知の{$role}（{$model->getMorphClass()} ".($model->getKey() ?? '未保存').'）';

        if (! $model->exists || $model->getKey() === null) {
            throw new LogicException("{$label}が保存されていません。");
        }

        $companyId = $model->getAttribute('company_id');

        if ($companyId === null) {
            throw new LogicException("{$label}の company_id が読み込まれていません。");
        }

        if ((int) $companyId !== CurrentCompany::id()) {
            throw new LogicException("{$label}が他社の行です。");
        }
    }
}
