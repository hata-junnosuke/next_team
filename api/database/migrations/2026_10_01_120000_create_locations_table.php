<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 拠点（F-01）。企業ごとに持つので company_id に INDEX を張る（docs/01 の業務テーブル）。
 * SQLite は外部キーに索引を自動で作らないので、constrained() とは別に index() を明示する。
 * 企業を消すときに拠点が残っていたら止める（restrictOnDelete）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->index()->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('address')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};
