<?php

namespace App\Http\Resources;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 応募の JSON（docs/01 の API 契約（応募・確定））:
 * {id, worker: {id, name}, status: {value, label}, confirmed_at, confirmed_hourly_wage, confirmed_transport_allowance,
 *  cancel_reason: {value, label} | null, cancelled_at, attendance: AttendanceResource | null, can: {confirm, reject, cancel, attend}}
 *
 * クエリは発行しない（N+1 の元）。worker と attendance は with('worker', 'attendance') で読み込んでから渡す
 * （ApplicationController::RELATIONS）。
 * https://readouble.com/laravel/13.x/ja/eloquent-resources.html
 *
 * @mixin Application
 */
class ApplicationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'worker' => new WorkerSummaryResource($this->whenLoaded('worker')),
            'status' => ['value' => $this->status->value, 'label' => $this->status->label()],
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'confirmed_hourly_wage' => $this->confirmed_hourly_wage,
            'confirmed_transport_allowance' => $this->confirmed_transport_allowance,
            'cancel_reason' => $this->cancel_reason === null
                ? null
                : ['value' => $this->cancel_reason->value, 'label' => $this->cancel_reason->label()],
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            // 勤怠がまだなければ null（whenLoaded が null の関連を null のまま返す）
            'attendance' => $this->whenLoaded('attendance', fn () => $this->attendance === null ? null : new AttendanceResource($this->attendance)),
            // 画面のボタンの出し分け。「状態として可能か」だけで、定員は見ない（定員超過は承認時の 422 で伝える。docs/01 の API 契約（応募・確定））
            'can' => [
                'confirm' => $this->status->canTransitionTo(ApplicationStatus::Confirmed),
                'reject' => $this->status->canTransitionTo(ApplicationStatus::Rejected),
                'cancel' => $this->status->canTransitionTo(ApplicationStatus::Cancelled),
                // 勤怠の入力は確定済みだけ（AttendanceService と同じ条件）
                'attend' => $this->status === ApplicationStatus::Confirmed,
            ],
        ];
    }
}
