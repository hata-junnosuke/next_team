<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 集計出力の依頼（F-23。docs/01 の非同期用のテーブル）。
 * - batch_id は job_batches.id（Bus::batch() の進捗を引く）。job_batches は雛形の jobs マイグレーションにある
 * - file_path は Storage::disk('local') 上の相対パス。ダウンロードはこの列だけを使い、入力からパスを組み立てない
 * INDEX: (company_id, created_at)（一覧を新しい順に 20 件）、(company_id, month)（同じ月が生成中かの判定）。
 * https://readouble.com/laravel/13.x/ja/migrations.html
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->index()->constrained()->restrictOnDelete();
            $table->char('month', 7);
            $table->string('status')->default('queued');
            $table->string('batch_id')->nullable();
            $table->string('file_path')->nullable();
            $table->unsignedInteger('row_count')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('failed_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'created_at']);
            $table->index(['company_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_exports');
    }
};
