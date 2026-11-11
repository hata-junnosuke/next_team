<?php

namespace App\Http\Resources\Worker;

use App\Models\Location;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ワーカーアプリの絞り込みの拠点の選択肢（docs/01 の 6.11）: {id, name}。住所や募集の件数は返さない
 *
 * @mixin Location
 */
class WorkerLocationResource extends JsonResource
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
