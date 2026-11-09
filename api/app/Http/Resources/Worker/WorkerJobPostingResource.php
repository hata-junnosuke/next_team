<?php

namespace App\Http\Resources\Worker;

use App\Models\Application;
use App\Models\JobPosting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;

/**
 * ワーカーアプリに返す募集（docs/01 の 6.11 の WorkerJobPostingResource）:
 * {id, location: {id, name}, job_title, work_date, start_time, end_time, hourly_wage, transport_allowance, capacity, remaining,
 *  status: {value, label}, my_application: {id, status: {value, label}} | null, can: {apply}}
 *
 * ほかのワーカーの氏名・応募数・応募の内訳は返さない（管理者用の JobPostingResource とは別にしている理由）。
 * 読み込み（location・確定数・myApplication）は WorkerPortalService が済ませてから渡す。クエリは発行しない。
 * 残り枠と応募できるかの判定は JobPosting（remainingSlots() / canBeAppliedBy()）の 1 か所を呼ぶ。
 *
 * @mixin JobPosting
 */
class WorkerJobPostingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // 読み込まずに $this->myApplication を触ると、未定義のリレーションとして例外になる。
        // 黙って null（応募なし）を返さないよう、読み込み漏れはここで止める
        if (! $this->resource->relationLoaded('myApplication')) {
            throw new LogicException('WorkerJobPostingResource には WorkerPortalService で読み込んだ募集を渡してください。');
        }

        /** @var Application|null $mine */
        $mine = $this->resource->getRelation('myApplication');

        return [
            'id' => $this->id,
            'location' => ['id' => $this->location->id, 'name' => $this->location->name],
            'job_title' => $this->job_title,
            'work_date' => $this->work_date->toDateString(),
            // time 列は書き方によって "09:00:00" で返ることがあるので "09:00" にそろえる
            'start_time' => substr((string) $this->start_time, 0, 5),
            'end_time' => substr((string) $this->end_time, 0, 5),
            'hourly_wage' => $this->hourly_wage,
            'transport_allowance' => $this->transport_allowance,
            'capacity' => $this->capacity,
            'remaining' => $this->remainingSlots(),
            'status' => ['value' => $this->status->value, 'label' => $this->status->label()],
            'my_application' => $mine === null ? null : [
                'id' => $mine->id,
                'status' => ['value' => $mine->status->value, 'label' => $mine->status->label()],
            ],
            'can' => [
                'apply' => $this->canBeAppliedBy($mine),
            ],
        ];
    }
}
