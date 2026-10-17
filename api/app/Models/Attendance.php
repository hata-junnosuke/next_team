<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\AttendanceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 勤怠（F-15、F-16）。応募と 1 対 1。
 * worked_minutes / wage_pay / total_pay は #[Fillable] に入れない。AttendanceService が PayrollCalculator の結果を
 * forceFill で入れて保存時に確定する（リクエストの値で金額を書き換えさせない。集計はこれを SUM する）。
 */
#[Fillable(['application_id', 'clock_in', 'clock_out', 'break_minutes'])]
class Attendance extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<AttendanceFactory> */
    use HasFactory;

    /**
     * DB の既定値と揃える（docs/01 のモデル）
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'break_minutes' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'clock_in' => 'immutable_datetime',
            'clock_out' => 'immutable_datetime',
            'break_minutes' => 'integer',
            'worked_minutes' => 'integer',
            'wage_pay' => 'integer',
            'total_pay' => 'integer',
        ];
    }

    /**
     * 同じ企業でなければならない親（BelongsToCompany が保存時に確かめる）
     *
     * @return array<string, class-string<Model>>
     */
    public function companyScopedParents(): array
    {
        return ['application_id' => Application::class];
    }

    /**
     * @return BelongsTo<Application, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }
}
