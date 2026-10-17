<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 応募（F-09〜F-14）。
 * - UNIQUE (job_posting_id, worker_id): 同じワーカーの二重応募を DB でも拒否する（F-09）。
 *   job_posting_id が先頭なので、この UNIQUE が job_posting_id 単独の索引も兼ねる
 * - confirmed_hourly_wage / confirmed_transport_allowance: 確定時に募集の値をコピーして固定する。報酬はこの値だけを見る
 * - 応募は消さない（キャンセルで終える）ので、募集・ワーカーへの外部キーは restrictOnDelete
 * INDEX: (job_posting_id, status) は確定数・応募数の数え上げ、(company_id, status) は未処理応募数（ダッシュボード）、
 * worker_id はワーカー側からの参照と外部キーの検査用（docs/01 の業務テーブルの表にはないが、SQLite は外部キーに索引を作らないため足す）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('job_posting_id')->constrained()->restrictOnDelete();
            $table->foreignId('worker_id')->index()->constrained()->restrictOnDelete();
            $table->string('status')->default('applied');
            $table->dateTime('confirmed_at')->nullable();
            $table->unsignedInteger('confirmed_hourly_wage')->nullable();
            $table->unsignedInteger('confirmed_transport_allowance')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['job_posting_id', 'worker_id']);
            $table->index(['job_posting_id', 'status']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('applications');
    }
};
