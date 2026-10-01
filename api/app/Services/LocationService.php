<?php

namespace App\Services;

use App\Exceptions\DomainException;
use App\Models\Location;
use Illuminate\Support\Facades\DB;

/**
 * 拠点の業務ルール（docs/01 4.5）。
 * 登録・更新は単純な CRUD なのでコントローラから Eloquent を直接呼ぶ。ルールがある削除だけここに置く。
 */
class LocationService
{
    /**
     * 募集に使われていない拠点だけを物理削除する（docs/01 4.6「削除」）。
     * 使われていれば DomainException（→ 422）。DB の restrictOnDelete でも守られるが、
     * それだと 500 になるので先に業務ルールとして判定する。
     * 判定と削除の間に募集が作られないよう、同じトランザクション（SQLite は BEGIN IMMEDIATE）で行う。
     *
     * @throws DomainException
     */
    public function delete(Location $location): void
    {
        DB::transaction(function () use ($location) {
            if ($location->jobPostings()->exists()) {
                throw new DomainException('募集に使われている拠点は削除できません。');
            }

            $location->delete();
        });
    }
}
