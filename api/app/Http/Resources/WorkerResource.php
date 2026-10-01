<?php

namespace App\Http\Resources;

use App\Models\Worker;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ワーカーの JSON（docs/01 6.3）: {id, name, email, phone, is_active}
 *
 * @mixin Worker
 */
class WorkerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'is_active' => $this->is_active,
        ];
    }
}
