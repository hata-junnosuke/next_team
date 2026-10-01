<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\LocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * 拠点（F-01）。BelongsToCompany で自社の行だけが見え、company_id は作成時に自動で埋まる。
 * company_id は #[Fillable] に入れない（リクエストの値で他社に付け替えられないようにする）。
 */
#[Fillable(['name', 'address'])]
class Location extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<LocationFactory> */
    use HasFactory;
}
