<?php

namespace App\Http\Resources;

use App\Services\MonthlyReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 月次集計の JSON（docs/01 6.7）:
 * {month, by_worker: [{worker_id, name, shifts, worked_minutes, total_pay}],
 *  by_location: [{location_id, name, shifts, worked_minutes, total_pay}], total: {shifts, worked_minutes, total_pay}}
 * モデルではなく値オブジェクト MonthlyReport を包む（集計は済んでいるのでクエリは発行しない）。
 * https://readouble.com/laravel/13.x/ja/eloquent-resources.html
 *
 * @property MonthlyReport $resource
 */
class MonthlyReportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'month' => $this->resource->month,
            'by_worker' => $this->resource->byWorker,
            'by_location' => $this->resource->byLocation,
            'total' => [
                'shifts' => $this->resource->totalShifts,
                'worked_minutes' => $this->resource->totalWorkedMinutes,
                'total_pay' => $this->resource->totalPay,
            ],
        ];
    }
}
