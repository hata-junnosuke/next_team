<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\WorkerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ワーカー（F-02〜F-04）。削除せず is_active で無効化する（要件 7 章）。
 * company_id は #[Fillable] に入れない（BelongsToCompany が CurrentCompany::id() で埋める）。
 */
#[Fillable(['name', 'email', 'phone', 'is_active'])]
class Worker extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<WorkerFactory> */
    use HasFactory;

    /**
     * DB の既定値と揃える（create() 直後のメモリ上の値が null にならないように。docs/01 4.3）
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Application, $this>
     */
    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }
}
