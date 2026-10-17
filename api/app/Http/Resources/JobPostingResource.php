<?php

namespace App\Http\Resources;

use App\Enums\JobPostingStatus;
use App\Models\JobPosting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 募集の JSON（docs/01 の API 契約（募集））:
 * {id, location: {id, name}, job_title, work_date, start_time, end_time, capacity, hourly_wage, transport_allowance,
 *  status: {value, label}, applications_count, confirmed_count, can: {edit, close, cancel, apply}}
 *
 * クエリは発行しない（N+1 の元）。location は with('location')、件数は
 * withCount(['applications', 'confirmedApplications']) で読み込んでから渡す（JobPostingController::withSummary()）。
 * https://readouble.com/laravel/13.x/ja/eloquent-resources.html
 *
 * @mixin JobPosting
 */
class JobPostingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'location' => $this->whenLoaded('location', fn () => [
                'id' => $this->location->id,
                'name' => $this->location->name,
            ]),
            'job_title' => $this->job_title,
            'work_date' => $this->work_date->toDateString(),
            // time 列は書き方によって "09:00:00" で返ることがあるので "09:00" にそろえる
            'start_time' => substr((string) $this->start_time, 0, 5),
            'end_time' => substr((string) $this->end_time, 0, 5),
            'capacity' => $this->capacity,
            'hourly_wage' => $this->hourly_wage,
            'transport_allowance' => $this->transport_allowance,
            'status' => ['value' => $this->status->value, 'label' => $this->status->label()],
            'applications_count' => $this->whenCounted('applications'),
            'confirmed_count' => $this->whenCounted('confirmedApplications'),
            // 画面のボタンの出し分け。判定の正は API（サービスと遷移表）で、web はこれを見て出すだけ
            'can' => [
                'edit' => $this->status->isEditable(),
                'close' => $this->status->canTransitionTo(JobPostingStatus::Closed),
                'cancel' => $this->status->canTransitionTo(JobPostingStatus::Cancelled),
                'apply' => $this->status->acceptsApplications(),
            ],
        ];
    }
}
