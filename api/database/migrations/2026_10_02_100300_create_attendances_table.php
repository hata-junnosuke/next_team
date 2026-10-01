<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 勤怠（F-15、F-16）。応募と 1 対 1（application_id の UNIQUE）。
 * - clock_out は深夜跨ぎなら翌日の日時になる（退勤 ≤ 出勤なら翌日扱い。判定は AttendanceService）
 * - worked_minutes / wage_pay / total_pay は保存時に確定した値。月次集計はこれを SUM する（計算し直さない）
 * INDEX: (company_id, clock_in)。application_id の UNIQUE が外部キーの索引を兼ねる。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('application_id')->unique()->constrained()->restrictOnDelete();
            $table->dateTime('clock_in');
            $table->dateTime('clock_out');
            $table->unsignedInteger('break_minutes')->default(0);
            $table->unsignedInteger('worked_minutes');
            $table->unsignedInteger('wage_pay');
            $table->unsignedInteger('total_pay');
            $table->timestamps();

            $table->index(['company_id', 'clock_in']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
