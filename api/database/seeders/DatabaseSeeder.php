<?php

namespace Database\Seeders;

use App\Enums\CancelReason;
use App\Models\Application;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\JobPosting;
use App\Models\Location;
use App\Models\User;
use App\Models\Worker;
use App\Support\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * 開発用のデータ（make fresh）。Rails の db/seeds.rb に相当。
 *
 * - 自社: 管理者 admin@example.com / password、拠点 4、ワーカー 6（うち無効 1）、
 *   今日以降の募集（募集中・締切・中止）と応募（応募中・確定・却下・キャンセル）、先月と今日の勤怠（月次集計・深夜跨ぎの例）
 * - 他社: 管理者 other@example.com / password と拠点・ワーカー・募集・勤怠を少し（分離の手動確認用。自社の画面に出ないこと）
 *
 * BelongsToCompany が company_id を CurrentCompany::id() で埋めるので、企業ごとに runAs() の中で作る。
 * 状態はファクトリの state で直接作る（シードは「その状態のデータ」を置くだけ。アプリの操作では必ずサービスを通る）。
 * 雛形の WithoutModelEvents は付けない（creating が動かず company_id が埋まらなくなる）。
 * 日付は実行した日からの相対で作るので、いつ make fresh してもダッシュボードに出る。
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

        CurrentCompany::runAs($company->id, fn () => $this->seedOwnCompany());

        $other = Company::factory()->create(['name' => '他社株式会社']);
        User::factory()->for($other)->create([
            'name' => '他社 管理者',
            'email' => 'other@example.com',
        ]);

        CurrentCompany::runAs($other->id, fn () => $this->seedOtherCompany());
    }

    private function seedOwnCompany(): void
    {
        $locations = [];
        foreach ([
            ['name' => '渋谷店', 'address' => '東京都渋谷区道玄坂1-1-1'],
            ['name' => '新宿店', 'address' => '東京都新宿区西新宿2-2-2'],
            ['name' => '池袋店', 'address' => '東京都豊島区南池袋3-3-3'],
            ['name' => '横浜倉庫', 'address' => null], // 募集に使わない（削除できる拠点の例）
        ] as $attributes) {
            $locations[] = Location::factory()->create($attributes);
        }
        [$shibuya, $shinjuku, $ikebukuro] = $locations;

        $workers = [];
        foreach ([
            ['name' => '佐藤 一郎', 'email' => 'sato@example.com', 'phone' => '090-1111-1111'],
            ['name' => '鈴木 花子', 'email' => 'suzuki@example.com', 'phone' => null],
            ['name' => '高橋 健太', 'email' => 'takahashi@example.com', 'phone' => '090-3333-3333'],
            ['name' => '田中 美咲', 'email' => 'tanaka@example.com', 'phone' => null],
            ['name' => '伊藤 翔', 'email' => 'ito@example.com', 'phone' => '090-5555-5555'],
        ] as $attributes) {
            $workers[] = Worker::factory()->create($attributes);
        }
        [$sato, $suzuki, $takahashi, $tanaka, $ito] = $workers;
        // 無効のワーカー（F-03。応募の候補に出ない）
        Worker::factory()->inactive()->create(['name' => '渡辺 休止', 'email' => 'watanabe@example.com']);

        $today = CarbonImmutable::today();

        // 明日・募集中・定員 3: 確定 2・応募中 1・却下 1・キャンセル（ワーカー都合）1 → 残り 1 枠。承認・却下・キャンセルを試せる
        $tomorrow = $this->posting($shibuya, $today->addDay(), ['job_title' => 'ピッキング', 'capacity' => 3]);
        Application::factory()->for($tomorrow)->for($sato)->confirmed()->create();
        Application::factory()->for($tomorrow)->for($suzuki)->confirmed()->create();
        Application::factory()->for($tomorrow)->for($takahashi)->create();
        Application::factory()->for($tomorrow)->for($tanaka)->rejected()->create();
        Application::factory()->for($tomorrow)->for($ito)->cancelled(CancelReason::Worker)->create();

        // 3 日後・募集中・定員 1: 応募中 2 → 1 人承認すると、もう 1 人は「定員に達しているため承認できません。」
        $full = $this->posting($shinjuku, $today->addDays(3), ['job_title' => '品出し', 'capacity' => 1, 'hourly_wage' => 1250, 'transport_allowance' => 0]);
        Application::factory()->for($full)->for($sato)->create();
        Application::factory()->for($full)->for($tanaka)->create();

        // 5 日後・締切: 確定 1・応募中 1 → 締切後も応募中は承認できる（F-07）
        $closed = JobPosting::factory()->closed()->for($ikebukuro)->create([
            'work_date' => $today->addDays(5)->toDateString(), 'job_title' => '検品',
        ]);
        Application::factory()->for($closed)->for($suzuki)->confirmed()->create();
        Application::factory()->for($closed)->for($ito)->create();

        // 7 日後・中止: 確定は企業都合キャンセル、応募中は却下になった後の形
        $cancelled = JobPosting::factory()->cancelled()->for($shibuya)->create([
            'work_date' => $today->addWeek()->toDateString(), 'job_title' => '梱包',
        ]);
        Application::factory()->for($cancelled)->for($takahashi)->cancelled(CancelReason::Company)->create();
        Application::factory()->for($cancelled)->for($sato)->rejected()->create();

        // 10 日後・募集中・応募なし（複製・編集・中止を試す用）
        $this->posting($shinjuku, $today->addDays(10), ['job_title' => 'レジ', 'start_time' => '10:00', 'end_time' => '15:00']);

        // 今日・締切: 確定 2。1 件は勤怠入力済み、1 件は未入力（勤怠入力を試す用）
        $todayPosting = JobPosting::factory()->closed()->for($shibuya)->create([
            'work_date' => $today->toDateString(), 'job_title' => 'ピッキング',
        ]);
        Attendance::factory()->for(Application::factory()->for($todayPosting)->for($sato)->confirmed())->create();
        Application::factory()->for($todayPosting)->for($suzuki)->confirmed()->create();

        // 先月: 勤怠入力済みの勤務（月次集計の例）。深夜跨ぎ（22:00-翌5:00）を 1 件含む
        $lastMonth = $today->subMonthNoOverflow()->startOfMonth();
        $day = $this->posting($shibuya, $lastMonth->addDays(9), ['job_title' => 'ピッキング'], closed: true);
        $night = $this->posting($ikebukuro, $lastMonth->addDays(14), [
            'job_title' => '夜間検品', 'start_time' => '22:00', 'end_time' => '05:00', 'hourly_wage' => 1625, 'transport_allowance' => 800,
        ], closed: true);
        foreach ([[$day, $sato], [$day, $tanaka], [$night, $suzuki], [$night, $ito]] as [$posting, $worker]) {
            Attendance::factory()->for(Application::factory()->for($posting)->for($worker)->confirmed())->create();
        }
    }

    private function seedOtherCompany(): void
    {
        $location = Location::factory()->create(['name' => '他社の拠点（自社の画面に出てはいけない）', 'address' => '大阪府大阪市1-1-1']);
        $worker = Worker::factory()->create(['name' => '他社 ワーカー', 'email' => 'other-worker@example.com']);
        $applicant = Worker::factory()->create(['name' => '他社 応募者', 'email' => 'other-applicant@example.com']);

        $today = CarbonImmutable::today();
        $upcoming = $this->posting($location, $today->addDays(2), ['job_title' => '他社の募集（自社の画面に出てはいけない）']);
        Application::factory()->for($upcoming)->for($applicant)->create();

        $past = $this->posting($location, $today->subMonthNoOverflow()->startOfMonth()->addDays(9), ['job_title' => '他社の過去の募集'], closed: true);
        Attendance::factory()->for(Application::factory()->for($past)->for($worker)->confirmed())->create();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function posting(Location $location, CarbonImmutable $workDate, array $attributes = [], bool $closed = false): JobPosting
    {
        $factory = JobPosting::factory()->for($location);

        return ($closed ? $factory->closed() : $factory)->create([
            'work_date' => $workDate->toDateString(),
            ...$attributes,
        ]);
    }
}
