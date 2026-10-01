<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ワーカー（F-02〜F-04）。第3弾からメールは必須（通知の宛先）。メールの一意制約は要件にないので付けない。
 * INDEX: (company_id, is_active) は is_active の絞り込みと applicable-workers、(company_id, name) は氏名検索と並び順のため。
 * SQLite は外部キーに索引を自動で作らないので company_id にも index() を明示する（P1-02 の教訓）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->index()->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('email');
            $table->string('phone')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['company_id', 'is_active']);
            $table->index(['company_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workers');
    }
};
