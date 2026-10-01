<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Location;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Database\Seeder;

/**
 * 開発用のデータ（make fresh）。Rails の db/seeds.rb に相当。
 *
 * - 自社: 管理者 admin@example.com / password と拠点数件
 * - 他社: 管理者 other@example.com / password と拠点 1 件（分離の手動確認用。自社の画面に出ないこと）
 *
 * 拠点は BelongsToCompany が company_id を CurrentCompany::id() で埋めるので、企業ごとに runAs() の中で作る。
 * https://readouble.com/laravel/13.x/ja/seeding.html
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 固定パスワードの管理者を本番に作らない
        if (app()->isProduction()) {
            $this->command?->error('本番環境では DatabaseSeeder を実行しません。');

            return;
        }

        $company = Company::factory()->create(['name' => '株式会社サンプル']);
        User::factory()->for($company)->create([
            'name' => '管理者 太郎',
            'email' => 'admin@example.com',
        ]);

        CurrentCompany::runAs($company->id, function () {
            foreach ([
                ['name' => '渋谷店', 'address' => '東京都渋谷区道玄坂1-1-1'],
                ['name' => '新宿店', 'address' => '東京都新宿区西新宿2-2-2'],
                ['name' => '池袋店', 'address' => '東京都豊島区南池袋3-3-3'],
                ['name' => '横浜倉庫', 'address' => null],
            ] as $attributes) {
                Location::factory()->create($attributes);
            }
        });

        $other = Company::factory()->create(['name' => '他社株式会社']);
        User::factory()->for($other)->create([
            'name' => '他社 管理者',
            'email' => 'other@example.com',
        ]);

        CurrentCompany::runAs($other->id, function () {
            Location::factory()->create(['name' => '他社の拠点（自社の画面に出てはいけない）', 'address' => '大阪府大阪市1-1-1']);
        });
    }
}
