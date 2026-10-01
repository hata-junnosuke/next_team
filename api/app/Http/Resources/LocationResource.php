<?php

namespace App\Http\Resources;

use App\Models\Location;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 拠点の JSON（docs/01 6.2）: {id, name, address, job_postings_count, deletable}
 *
 * @mixin Location
 */
class LocationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'address' => $this->address,
            // P1 では募集（job_postings）がまだないので固定値。P2-02 で withCount の実値と「0 件なら削除可」にする
            'job_postings_count' => 0,
            'deletable' => true,
        ];
    }
}
