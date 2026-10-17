<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 通知の記録（F-20〜F-24。docs/01 3.2）。1 通 1 行。
 * - dedupe_key の UNIQUE が二重送信を防ぐ最後の砦（claim の検索をすり抜けた同時実行も DB が拒否する）
 * - recipient / subject はポリモーフィック（Rails の polymorphic: true）。*_type には morph map の短い名前
 *   （worker / user / application / job_posting / report_export）が入る。AppServiceProvider の enforceMorphMap
 * - recipient_email は送信時点の宛先を残す（あとでワーカーのメールが変わっても、誰に送ったかが分かる）
 * INDEX: (company_id, created_at)（F-24 の通知履歴を新しい順に）。morphs() は (type, id) の索引を自分で張る。
 * https://readouble.com/laravel/13.x/ja/migrations.html
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->index()->constrained()->restrictOnDelete();
            $table->string('kind');
            $table->morphs('recipient');
            $table->morphs('subject');
            $table->string('recipient_email');
            $table->string('dedupe_key')->unique();
            $table->string('status')->default('pending');
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('failed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
    }
};
