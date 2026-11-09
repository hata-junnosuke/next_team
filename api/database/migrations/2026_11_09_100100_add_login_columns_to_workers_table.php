<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ワーカーのログイン用の列（ワーカー Step 5。docs/01 の 3.1）。どれも初回登録（WorkerAuthService::register()）で入る。
 * - password: hashed cast。未登録なら NULL
 * - login_email: 初回登録のときに email を小文字にして写す。ログインの識別子
 * - registered_at: 初回登録の日時。NULL なら未登録
 * UNIQUE (company_id, login_email): 登録済みのワーカーのメールを同じ企業の中で一意にする。未登録は NULL なので縛らない
 * （NULL は UNIQUE で重なってよい。部分インデックスは MySQL にないので使わない）。
 * https://readouble.com/laravel/13.x/ja/migrations.html#creating-indexes
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            $table->string('password')->nullable();
            $table->string('login_email')->nullable();
            $table->dateTime('registered_at')->nullable();

            $table->unique(['company_id', 'login_email']);
        });
    }

    public function down(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'login_email']);
            $table->dropColumn(['password', 'login_email', 'registered_at']);
        });
    }
};
