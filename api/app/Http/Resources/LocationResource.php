<?php

namespace App\Http\Resources;

use App\Models\Location;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 拠点の JSON（docs/01 の API 契約（拠点））: {id, name, address, job_postings_count, deletable}
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
            // コントローラが withCount('jobPostings') / loadCount で数えた値を使う（Resource でクエリを発行しない = N+1 にしない）。
            // 件数を読み込まないとキーが消えるので、このリソースを返す箇所では必ず withCount / loadCount する。
            // 削除できるのは募集が 0 件のときだけ（docs/01 の業務ルールの決定）
            'job_postings_count' => $this->whenCounted('jobPostings'),
            'deletable' => $this->whenCounted('jobPostings', fn (int $count) => $count === 0),
        ];
    }
}
