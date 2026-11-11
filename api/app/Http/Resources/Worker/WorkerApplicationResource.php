<?php

namespace App\Http\Resources\Worker;

use App\Models\Application;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ワーカーアプリに返す自分の応募（docs/01 の 6.11 の WorkerApplicationResource。F-32 の応答と F-33 の一覧で共有）:
 * {id, job_posting: {id, job_title, work_date, start_time, end_time, location: {id, name}}, status: {value, label},
 *  applied_at, confirmed_at, confirmed_hourly_wage, confirmed_transport_allowance, cancel_reason: {value, label} | null, cancelled_at}
 *
 * applied_at は応募の created_at。勤怠・報酬・ワーカーの氏名・管理者向けの can は返さない（管理者用の ApplicationResource と別にしている理由）。
 * 呼び出し側が jobPosting.location を読み込んでから渡す。
 *
 * @mixin Application
 */
class WorkerApplicationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $posting = $this->jobPosting;

        return [
            'id' => $this->id,
            'job_posting' => [
                'id' => $posting->id,
                'job_title' => $posting->job_title,
                'work_date' => $posting->work_date->toDateString(),
                // time 列は書き方によって "09:00:00" で返ることがあるので "09:00" にそろえる（WorkerJobPostingResource と同じ）
                'start_time' => substr((string) $posting->start_time, 0, 5),
                'end_time' => substr((string) $posting->end_time, 0, 5),
                'location' => ['id' => $posting->location->id, 'name' => $posting->location->name],
            ],
            'status' => ['value' => $this->status->value, 'label' => $this->status->label()],
            'applied_at' => $this->created_at?->toIso8601String(),
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'confirmed_hourly_wage' => $this->confirmed_hourly_wage,
            'confirmed_transport_allowance' => $this->confirmed_transport_allowance,
            'cancel_reason' => $this->cancel_reason === null
                ? null
                : ['value' => $this->cancel_reason->value, 'label' => $this->cancel_reason->label()],
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
        ];
    }
}
