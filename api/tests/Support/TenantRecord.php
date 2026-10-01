<?php

namespace Tests\Support;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * BelongsToCompany の検証専用モデル。P1 には業務モデルがまだないため、テストの中だけでテーブルを作って使う。
 */
class TenantRecord extends Model
{
    use BelongsToCompany;

    protected $table = 'tenant_records';

    protected $guarded = [];
}
