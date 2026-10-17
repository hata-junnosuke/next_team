<?php

namespace App\Http\Resources;

use App\Models\Application;
use App\Models\JobPosting;
use App\Models\NotificationLog;
use App\Models\ReportExport;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 通知の記録の JSON（docs/01 の API 契約（通知履歴））:
 * {id, kind: {value, label}, recipient: {type: {value, label}, name, email}, subject: {type: {value, label}, id, label}, status: {value, label}, sent_at, failed_at, error, created_at}
 *
 * - recipient.type / subject.type は {value, label}。value は morph map の短い名前（worker / user、application / job_posting / report_export）
 * - subject.label はそのまま画面に出す表示用の文字列。勤務日は画面の表示規約（docs/01 のフロント契約）の 2026/10/10（土）の形
 * - recipient.email は記録時のアドレス（recipient_email）。今のアドレスに変わっていても、実際に送った宛先を見せる
 * - 宛先・対象の行がもうない（morphs に外部キーはない）ときも落とさない。name は記録時のアドレス、label は「削除された◯◯」
 * - error は NotificationLogService が残した値（例外のクラス名と固定の文言。生のメッセージは入っていない）をそのまま返す
 * - 関連はコントローラで先に読んでおく（Resource でクエリを発行しない）
 *
 * @mixin NotificationLog
 */
class NotificationLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $recipient = $this->recipient;
        $subject = $this->subject;

        return [
            'id' => $this->id,
            'kind' => ['value' => $this->kind->value, 'label' => $this->kind->label()],
            'recipient' => [
                'type' => self::morphType($this->recipient_type),
                'name' => $recipient?->getAttribute('name') ?? $this->recipient_email,
                'email' => $this->recipient_email,
            ],
            'subject' => [
                'type' => self::morphType($this->subject_type),
                'id' => $this->subject_id,
                'label' => $subject !== null ? self::subjectLabel($subject) : '削除された'.self::morphLabel($this->subject_type),
            ],
            'status' => ['value' => $this->status->value, 'label' => $this->status->label()],
            'sent_at' => $this->sent_at?->toIso8601String(),
            'failed_at' => $this->failed_at?->toIso8601String(),
            'error' => $this->error,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * 対象を人が読める文言にする
     * - 応募: 「{募集の職種} {勤務日（2026/10/10（土））} / {ワーカー名}」
     * - 募集: 「{職種} {勤務日}」
     * - 集計出力: 「{月} の月次集計」
     */
    private static function subjectLabel(Model $subject): string
    {
        return match (true) {
            $subject instanceof Application => self::postingLabel($subject->jobPosting)
                .' / '.($subject->worker->name ?? '削除されたワーカー'),
            $subject instanceof JobPosting => self::postingLabel($subject),
            $subject instanceof ReportExport => "{$subject->month} の月次集計",
            default => self::morphLabel($subject->getMorphClass()),
        };
    }

    private static function postingLabel(?JobPosting $posting): string
    {
        return $posting !== null
            ? "{$posting->job_title} {$posting->work_date->isoFormat('YYYY/MM/DD（ddd）')}"
            : '削除された募集';
    }

    /**
     * 宛先・対象の種類を {value, label} で返す（docs/01 の API 契約の共通。ラベルは API が返す）。value は morph map の短い名前
     *
     * @return array{value: string, label: string}
     */
    private static function morphType(string $morphType): array
    {
        return ['value' => $morphType, 'label' => self::morphLabel($morphType)];
    }

    private static function morphLabel(string $morphType): string
    {
        // *_type は morph map の短い名前なので、クラスに戻してから比べる（短い名前の文字列を二重に書かない）
        return match (Relation::getMorphedModel($morphType)) {
            Worker::class => 'ワーカー',
            User::class => '管理者',
            Application::class => '応募',
            JobPosting::class => '募集',
            ReportExport::class => '集計出力',
            default => '不明',
        };
    }
}
