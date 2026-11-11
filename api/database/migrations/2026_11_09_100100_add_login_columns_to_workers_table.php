<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ワーカーのログイン用の列と、メールの一意制約（ワーカー Step 5。docs/01 の 3.1）。
 * - password: hashed cast。未登録なら NULL。初回登録（WorkerAuthService::register()）で入る
 * - registered_at: 初回登録の日時。NULL なら未登録
 * - email（既存の列）がログインの識別子を兼ねる。小文字（Worker::normalizeEmail()）で保存する
 * UNIQUE (company_id, email): 未登録も含め、同じ企業の中でメールを一意にする（他社とは重なってよい）。
 * SQLite の UNIQUE は大文字小文字を区別するので、小文字にそろえて保存することで大文字小文字だけ違うメールも重ならないようにする。
 * MySQL に移すときは email 列の照合順序を utf8mb4_0900_bin にする（_ci はアクセントも同じとみなし、UNIQUE とログインの照合が
 * SQLite より緩くなる。utf8mb4_bin は末尾の空白を同じとみなす PAD SPACE なので、NO PAD の 0900_bin にする。
 * 小文字で保存しているので、bin でも大文字小文字の扱いはそろう）。
 *
 * 順番は「重なりの検査 → 小文字化 → 列と UNIQUE の追加」。MySQL は DDL が自動コミットなので、DDL の途中で失敗すると
 * 列の追加だけが残って再実行も失敗する。失敗しうる検査を、書き込みと DDL より前に済ませる。
 * https://readouble.com/laravel/13.x/ja/migrations.html#creating-indexes
 */
return new class extends Migration
{
    public function up(): void
    {
        // モデル（Worker）を使わない: マイグレーションは後でモデルが変わっても同じ結果になるようにする。
        // また Worker には企業のグローバルスコープがあり、CurrentCompany が空のマイグレーションでは全社の行を読めない。
        // 小文字化は Worker::normalizeEmail() と同じ mb_strtolower を PHP で行う（SQL の LOWER() は SQLite で ASCII しか小文字にしない）
        $this->ensureNoDuplicateEmails();

        DB::table('workers')->select(['id', 'email'])->lazyById()->each(function (object $row) {
            $lower = mb_strtolower($row->email);
            if ($lower !== $row->email) {
                DB::table('workers')->where('id', $row->id)->update(['email' => $lower]);
            }
        });

        Schema::table('workers', function (Blueprint $table) {
            $table->string('password')->nullable();
            $table->dateTime('registered_at')->nullable();

            $table->unique(['company_id', 'email']);
        });
    }

    /**
     * email の小文字化は戻さない（元の表記を残していない）。列と UNIQUE だけを落とす
     */
    public function down(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'email']);
            $table->dropColumn(['password', 'registered_at']);
        });
    }

    /**
     * 小文字にすると同じ企業の中で重なる行があれば、何も書き込まずに止める（どちらを残すかは人が決める）。
     * 例外の文言に企業 ID と行 ID を書き、どの行を直せばよいかが分かるようにする
     */
    private function ensureNoDuplicateEmails(): void
    {
        /** @var array<string, list<int>> $idsByKey */
        $idsByKey = [];
        DB::table('workers')->select(['id', 'company_id', 'email'])->lazyById()->each(function (object $row) use (&$idsByKey) {
            $idsByKey[$row->company_id."\0".mb_strtolower($row->email)][] = (int) $row->id;
        });

        $duplicates = [];
        foreach ($idsByKey as $key => $ids) {
            if (count($ids) > 1) {
                $companyId = explode("\0", $key, 2)[0];
                $duplicates[] = "company_id={$companyId} の workers.id ".implode(', ', $ids);
            }
        }

        if ($duplicates !== []) {
            throw new RuntimeException(
                'workers.email を小文字にすると同じ企業の中で重なる行があります。直してから流し直してください: '.implode(' / ', $duplicates),
            );
        }
    }
};
