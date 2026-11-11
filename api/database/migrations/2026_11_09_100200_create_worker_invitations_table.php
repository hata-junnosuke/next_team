<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ワーカーの招待（F-26〜F-28。docs/01 の 3.2）。
 * - code_hash: 招待コードの HMAC（WorkerInvitation::hashCode()）。UNIQUE で、初回登録のときにこれで引く
 * - code: 暗号化した招待コード（encrypted cast なので text）。メールに書くためだけに持ち、使ったとき・取り消したときに NULL にする
 * - worker_id は restrictOnDelete（招待のあるワーカーは消せない。ワーカーは削除せず無効化する運用）
 * SQLite は外部キーに索引を自動で作らないので company_id と worker_id にも index() を明示する。
 * https://readouble.com/laravel/13.x/ja/migrations.html
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worker_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('worker_id')->index()->constrained()->restrictOnDelete();
            $table->string('code_hash')->unique();
            $table->text('code')->nullable();
            $table->dateTime('expires_at');
            $table->dateTime('used_at')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worker_invitations');
    }
};
