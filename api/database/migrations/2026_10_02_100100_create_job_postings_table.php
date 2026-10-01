<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 募集（F-05〜F-08）。
 * - location_id は restrictOnDelete: 募集に使われている拠点は DB でも消せない（F-01）
 * - status の既定は open。モデルの $attributes でも同じ値を持つ（create() 直後のメモリ上の値を揃える）
 * - 金額は円の整数。capacity >= 1 と hourly_wage >= 最低賃金は FormRequest で守る
 * INDEX: (company_id, work_date) は一覧の並びと日付の絞り込み・月次集計、(company_id, status) は状態の絞り込みとダッシュボード、
 * (location_id) は拠点の削除可否（使われているか）と拠点の絞り込み。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_postings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->index()->constrained()->restrictOnDelete();
            $table->string('job_title');
            $table->date('work_date');
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedInteger('capacity');
            $table->unsignedInteger('hourly_wage');
            $table->unsignedInteger('transport_allowance')->default(0);
            $table->string('status')->default('open');
            $table->timestamps();

            $table->index(['company_id', 'work_date']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_postings');
    }
};
