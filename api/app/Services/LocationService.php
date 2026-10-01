<?php

namespace App\Services;

use App\Models\Location;

/**
 * 拠点の業務ルール（docs/01 4.5）。
 * 登録・更新は単純な CRUD なのでコントローラから Eloquent を直接呼ぶ。ルールがある削除だけここに置く。
 */
class LocationService
{
    /**
     * P1 では削除するだけ。P2-02 で「募集に使われている拠点は削除できません。」（DomainException → 422）を足す。
     */
    public function delete(Location $location): void
    {
        $location->delete();
    }
}
