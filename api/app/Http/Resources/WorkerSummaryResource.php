<?php

namespace App\Http\Resources;

use App\Models\Worker;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ワーカーの要約 {id, name}。応募できるワーカーの一覧（docs/01 の API 契約（募集）の applicable-workers）と
 * ApplicationResource の worker に使う。
 *
 * @mixin Worker
 */
class WorkerSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
        ];
    }
}
