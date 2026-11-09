<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 企業コード（ワーカーのログインの識別子。docs/01 の 3.1、docs/00 の決定 68）。
 * 既存の行があるので、NULL 可で足す → 既存の行を埋める → NOT NULL + UNIQUE にする、の順で 1 本にまとめる。
 * 埋める処理はモデル（Company::creating）を使わずにここへ書き写す（モデルが後で変わってもこのマイグレーションの結果が変わらないように）。
 * https://readouble.com/laravel/13.x/ja/migrations.html#modifying-columns
 */
return new class extends Migration
{
    /** 紛らわしい 0 O 1 I L を除いた英大文字と数字（Company::CODE_CHARACTERS と同じ） */
    private const CHARACTERS = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('code')->nullable()->after('name');
        });

        $used = [];
        foreach (DB::table('companies')->orderBy('id')->pluck('id') as $id) {
            do {
                $code = $this->randomCode();
            } while (isset($used[$code]));

            $used[$code] = true;
            DB::table('companies')->where('id', $id)->update(['code' => $code]);
        }

        Schema::table('companies', function (Blueprint $table) {
            $table->string('code')->nullable(false)->change();
            $table->unique('code');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn('code');
        });
    }

    private function randomCode(): string
    {
        $code = '';
        for ($i = 0; $i < 6; $i++) {
            $code .= self::CHARACTERS[random_int(0, strlen(self::CHARACTERS) - 1)];
        }

        return $code;
    }
};
