<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\CancelReason;
use App\Models\Application;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\JobPosting;
use App\Models\Location;
use App\Models\Worker;
use App\Services\MonthlyReportService;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * F-17 月次集計、F-18 CSV（docs/01 4.6・6.7）。
 * 対象は「募集の勤務日が対象月に入る」「応募が確定」の勤怠。範囲は半開区間（>= 月初 AND < 翌月初）。
 * 既定の募集（9:00-17:00、時給 1,300 円、交通費 500 円）と勤怠（休憩 60 分）なら 420 分・賃金 9,100 円・合計 9,600 円。
 */
class MonthlyReportTest extends TestCase
{
    /**
     * 確定済みの応募に勤怠を 1 件作る（拠点・ワーカーを渡さなければ新しく作る）
     *
     * @param  array<string, mixed>  $posting
     */
    private function shift(string $date, ?Location $location = null, ?Worker $worker = null, array $posting = []): Attendance
    {
        $jobPosting = JobPosting::factory()
            ->onDate($date)
            ->for($location ?? Location::factory())
            ->create($posting);

        $application = Application::factory()
            ->for($jobPosting)
            ->for($worker ?? Worker::factory())
            ->confirmed()
            ->create();

        return Attendance::factory()->for($application)->create();
    }

    private function getReport(string $month): TestResponse
    {
        return $this->actingAsAdmin()->getJson("/api/reports/monthly?month={$month}");
    }

    /**
     * CSV の本文を BOM を除いて行の配列にする
     *
     * @return list<list<string>>
     */
    private static function parseCsv(string $body): array
    {
        $lines = preg_split('/\r\n|\n/', rtrim(substr($body, 3), "\r\n"));

        return array_map(fn (string $line) => str_getcsv($line, escape: ''), $lines);
    }

    public function test_対象月のワーカー別と拠点別と合計が出る(): void
    {
        $tokyo = Location::factory()->create(['name' => '東京倉庫']);
        $osaka = Location::factory()->create(['name' => '大阪倉庫']);
        $sato = Worker::factory()->create(['name' => '佐藤']);
        $suzuki = Worker::factory()->create(['name' => '鈴木']);

        $this->shift('2026-09-05', $tokyo, $sato);
        $this->shift('2026-09-12', $tokyo, $suzuki);
        // 時給 1,200 円: 420 分 → 8,400 円 + 交通費 500 円 = 8,900 円
        $this->shift('2026-09-20', $osaka, $sato, ['hourly_wage' => 1200]);

        $this->getReport('2026-09')
            ->assertOk()
            ->assertExactJson(['data' => [
                'month' => '2026-09',
                'by_worker' => [
                    ['worker_id' => $sato->id, 'name' => '佐藤', 'shifts' => 2, 'worked_minutes' => 840, 'total_pay' => 18500],
                    ['worker_id' => $suzuki->id, 'name' => '鈴木', 'shifts' => 1, 'worked_minutes' => 420, 'total_pay' => 9600],
                ],
                'by_location' => [
                    ['location_id' => $tokyo->id, 'name' => '東京倉庫', 'shifts' => 2, 'worked_minutes' => 840, 'total_pay' => 19200],
                    ['location_id' => $osaka->id, 'name' => '大阪倉庫', 'shifts' => 1, 'worked_minutes' => 420, 'total_pay' => 8900],
                ],
                'total' => ['shifts' => 3, 'worked_minutes' => 1260, 'total_pay' => 28100],
            ]]);
    }

    public function test_勤怠がない月は空の一覧と0の合計(): void
    {
        $this->getReport('2026-09')
            ->assertOk()
            ->assertExactJson(['data' => [
                'month' => '2026-09',
                'by_worker' => [],
                'by_location' => [],
                'total' => ['shifts' => 0, 'worked_minutes' => 0, 'total_pay' => 0],
            ]]);
    }

    public function test_月末の勤務が漏れない(): void
    {
        $this->shift('2026-09-01');
        $this->shift('2026-09-30');
        // 深夜跨ぎで退勤が翌月でも、勤務日（募集の work_date）の月に入る
        $this->shift('2026-09-30', posting: ['start_time' => '22:00', 'end_time' => '06:00']);

        $this->getReport('2026-09')
            ->assertOk()
            ->assertJsonPath('data.total.shifts', 3);
    }

    public function test_翌月1日の勤務は含まれない(): void
    {
        $this->shift('2026-08-31');
        $this->shift('2026-09-15');
        $this->shift('2026-10-01');

        $this->getReport('2026-09')
            ->assertOk()
            ->assertJsonPath('data.total.shifts', 1)
            ->assertJsonPath('data.total.total_pay', 9600);
    }

    public function test_12月は翌年1月1日の勤務を含まない(): void
    {
        $this->shift('2026-12-31');
        $this->shift('2027-01-01');

        $this->getReport('2026-12')
            ->assertOk()
            ->assertJsonPath('data.total.shifts', 1);
    }

    public function test_勤怠の後にキャンセルされた応募は集計しない(): void
    {
        $this->shift('2026-09-10');
        $cancelled = $this->shift('2026-09-11');
        $cancelled->application->transitionTo(ApplicationStatus::Cancelled, [
            'cancel_reason' => CancelReason::Worker,
            'cancelled_at' => now(),
        ]);

        $this->getReport('2026-09')
            ->assertOk()
            ->assertJsonPath('data.total.shifts', 1)
            ->assertJsonCount(1, 'data.by_worker')
            ->assertJsonCount(1, 'data.by_location');
    }

    public function test_他社の勤怠は集計に混ざらない(): void
    {
        $ours = $this->shift('2026-09-10');
        $other = Company::factory()->create();
        CurrentCompany::runAs($other->id, function () {
            $this->shift('2026-09-10');
            $this->shift('2026-09-11');
        });

        // 本番のリクエストは CurrentCompany が空の状態で始まる。setUp で入れた値に助けられないよう消してから叩く
        Context::forget('company_id');

        $this->getReport('2026-09')
            ->assertOk()
            ->assertJsonPath('data.total.shifts', 1)
            ->assertJsonPath('data.by_worker.0.worker_id', $ours->application->worker_id)
            ->assertJsonCount(1, 'data.by_worker')
            ->assertJsonPath('data.by_location.0.location_id', $ours->application->jobPosting->location_id)
            ->assertJsonCount(1, 'data.by_location');
    }

    public function test_csvはbomつきで7列(): void
    {
        $tokyo = Location::factory()->create(['name' => '東京倉庫']);
        $sato = Worker::factory()->create(['name' => '佐藤']);
        $this->shift('2026-09-05', $tokyo, $sato);
        $this->shift('2026-09-20', $tokyo, $sato, ['hourly_wage' => 1200, 'transport_allowance' => 0]);
        $this->shift('2026-10-01', $tokyo, $sato);

        $response = $this->actingAsAdmin()->get('/api/reports/monthly/csv?month=2026-09');

        $response->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('2026-09', (string) $response->headers->get('Content-Disposition'));

        $body = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
        $this->assertSame([
            ['ワーカー名', '拠点名', '勤務日', '労働分', '賃金', '交通費', '合計'],
            ['佐藤', '東京倉庫', '2026-09-05', '420', '9100', '500', '9600'],
            ['佐藤', '東京倉庫', '2026-09-20', '420', '8400', '0', '8400'],
        ], self::parseCsv($body));
    }

    /**
     * 引用符の中の改行・CR を含む CSV も読めるよう、ストリームから fgetcsv で読む（BOM は除く）
     *
     * @return list<list<string>>
     */
    private static function readCsv(string $body): array
    {
        $stream = fopen('php://memory', 'r+b');
        fwrite($stream, substr($body, 3));
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream, escape: '')) !== false) {
            $rows[] = $row;
        }
        fclose($stream);

        return $rows;
    }

    public function test_csvで等号で始まるセルは先頭にクォートが付く(): void
    {
        $this->shift(
            '2026-09-05',
            Location::factory()->create(['name' => '@SUM(A1)']),
            Worker::factory()->create(['name' => '=HYPERLINK("http://example.com")']),
        );
        $this->shift(
            '2026-09-06',
            Location::factory()->create(['name' => '-倉庫']),
            Worker::factory()->create(['name' => '+81山田']),
        );

        // タブ・CR で始めて、後ろの = を数式として読ませる手口（OWASP の CSV Injection）
        $this->shift(
            '2026-09-07',
            Location::factory()->create(['name' => "\r=1+1"]),
            Worker::factory()->create(['name' => "\t=1+1"]),
        );
        $this->shift(
            '2026-09-08',
            Location::factory()->create(['name' => '普通の倉庫']),
            Worker::factory()->create(['name' => '＝全角']),
        );

        $body = $this->actingAsAdmin()->get('/api/reports/monthly/csv?month=2026-09')->assertOk()->streamedContent();
        // セルの中の CR は fputcsv が " で囲むので、行の分割には頼らず PHP の CSV 読み取りで 1 行ずつ読む
        $rows = self::readCsv($body);

        $this->assertSame(["'=HYPERLINK(\"http://example.com\")", "'@SUM(A1)"], array_slice($rows[1], 0, 2));
        $this->assertSame(["'+81山田", "'-倉庫"], array_slice($rows[2], 0, 2));
        $this->assertSame(["'\t=1+1", "'\r=1+1"], array_slice($rows[3], 0, 2));
        // 全角の ＝ は表計算ソフトが数式として扱わないので、そのまま
        $this->assertSame(['＝全角', '普通の倉庫'], array_slice($rows[4], 0, 2));
        // 数値と日付はそのまま
        $this->assertSame(['2026-09-05', '420', '9100', '500', '9600'], array_slice($rows[1], 2));
    }

    public function test_csvに他社の勤怠は含まれない(): void
    {
        $this->shift('2026-09-10', worker: Worker::factory()->create(['name' => '自社の人']));
        $other = Company::factory()->create();
        CurrentCompany::runAs($other->id, fn () => $this->shift('2026-09-10', worker: Worker::factory()->create(['name' => '他社の人'])));

        Context::forget('company_id');

        $rows = self::parseCsv($this->actingAsAdmin()->get('/api/reports/monthly/csv?month=2026-09')->assertOk()->streamedContent());

        $this->assertCount(2, $rows);
        $this->assertSame('自社の人', $rows[1][0]);
    }

    public function test_csv行は拠点で絞り込める(): void
    {
        $tokyo = Location::factory()->create(['name' => '東京倉庫']);
        $osaka = Location::factory()->create(['name' => '大阪倉庫']);
        $this->shift('2026-09-05', $tokyo);
        $this->shift('2026-09-06', $osaka);

        $rows = iterator_to_array(app(MonthlyReportService::class)->csvRows('2026-09', $osaka->id), false);

        $this->assertCount(1, $rows);
        $this->assertSame('大阪倉庫', $rows[0][1]);
    }

    public function test_monthがないと422(): void
    {
        $this->actingAsAdmin()->getJson('/api/reports/monthly')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['month']);

        $this->actingAsAdmin()->getJson('/api/reports/monthly/csv')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['month']);
    }

    public function test_csvはacceptの指定がなくてもmonthがないと422のjson(): void
    {
        // ブラウザのダウンロード（web の Route Handler 経由）は Accept: application/json を付けない。
        // それでもリダイレクトではなく 422 の JSON になる（bootstrap/app.php の shouldRenderJsonWhen が常に JSON で返す）
        $this->actingAsAdmin()->get('/api/reports/monthly/csv')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['month']);

        $this->actingAsAdmin()->get('/api/reports/monthly/csv?month=2026-9')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['month']);
    }

    public function test_monthの形式エラーは書式記号ではなく年と月の日本語で案内する(): void
    {
        // 既定の文言は「対象月の形式が'Y-m'と一致しません。」で、PHP の書式記号が画面に出てしまう
        $this->actingAsAdmin()->getJson('/api/reports/monthly?month=2026-13')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['month' => '対象月は YYYY-MM の形式で指定してください。']);

        $this->actingAsAdmin()->get('/api/reports/monthly/csv?month=2026-9')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['month' => '対象月は YYYY-MM の形式で指定してください。']);
    }

    public function test_拠点idの一覧はその月に集計対象の勤怠がある自社の拠点だけ(): void
    {
        $tokyo = Location::factory()->create();
        $osaka = Location::factory()->create();
        $nagoya = Location::factory()->create();
        $sapporo = Location::factory()->create();
        $this->shift('2026-09-05', $osaka);
        $this->shift('2026-09-06', $osaka);
        $this->shift('2026-09-07', $tokyo);
        $this->shift('2026-10-01', $nagoya);
        // 勤怠の後にキャンセルされた応募しかない拠点は対象外
        $this->shift('2026-09-08', $sapporo)->application->transitionTo(ApplicationStatus::Cancelled, [
            'cancel_reason' => CancelReason::Worker,
            'cancelled_at' => now(),
        ]);
        $other = Company::factory()->create();
        CurrentCompany::runAs($other->id, fn () => $this->shift('2026-09-05'));

        $this->assertSame(
            [$tokyo->id, $osaka->id],
            app(MonthlyReportService::class)->locationIds('2026-09'),
        );
    }

    public function test_monthがyyyy_mmの形でないと422(): void
    {
        foreach (['2026-9', '2026/09', '2026-13', '202609', '2026-09-01'] as $month) {
            $this->getReport($month)
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['month']);
        }
    }

    // ---- ここから tester が補強（P2-06） ----

    public function test_月初0時に始まる勤務は対象月に入る(): void
    {
        $this->shift('2026-09-01', posting: ['start_time' => '00:00', 'end_time' => '08:00']);

        $this->getReport('2026-09')->assertOk()->assertJsonPath('data.total.shifts', 1);
        $this->getReport('2026-08')->assertOk()->assertJsonPath('data.total.shifts', 0);
    }

    public function test_前月末の深夜跨ぎは退勤が対象月でも前月に入る(): void
    {
        // 8/31 22:00 → 9/1 06:00。退勤（clock_out）は 9 月だが勤務日は 8 月
        $this->shift('2026-08-31', posting: ['start_time' => '22:00', 'end_time' => '06:00']);

        $this->getReport('2026-09')->assertOk()->assertJsonPath('data.total.shifts', 0);
        $this->getReport('2026-08')->assertOk()
            ->assertJsonPath('data.total.shifts', 1)
            ->assertJsonPath('data.total.worked_minutes', 420);
    }

    public function test_閏年の2月は29日を含み3月1日を含まない(): void
    {
        $this->shift('2028-01-31');
        $this->shift('2028-02-01');
        $this->shift('2028-02-29');
        $this->shift('2028-03-01');

        $this->getReport('2028-02')->assertOk()->assertJsonPath('data.total.shifts', 2);
    }

    public function test_平年の2月は28日まで(): void
    {
        $this->shift('2027-02-28');
        $this->shift('2027-03-01');

        $this->getReport('2027-02')->assertOk()->assertJsonPath('data.total.shifts', 1);
    }

    /**
     * join するテーブルのどれか 1 つでも他社の行なら、その勤怠は集計しない（join 先にはグローバルスコープが効かないため、
     * company_id 条件の明示を各テーブルごとに確かめる。行は直接書き換えて「他社の行を指す」状態を作る）
     */
    public function test_joinする各テーブルが他社の行なら集計にもcsvにも混ざらない(): void
    {
        $other = Company::factory()->create();
        $clean = $this->shift('2026-09-01', worker: Worker::factory()->create(['name' => '自社の人']));

        $tables = ['attendances', 'applications', 'job_postings', 'workers', 'locations'];
        foreach ($tables as $i => $table) {
            $attendance = $this->shift(sprintf('2026-09-%02d', $i + 2), worker: Worker::factory()->create(['name' => "{$table}が他社"]));
            $id = match ($table) {
                'attendances' => $attendance->id,
                'applications' => $attendance->application_id,
                'job_postings' => $attendance->application->job_posting_id,
                'workers' => $attendance->application->worker_id,
                'locations' => $attendance->application->jobPosting->location_id,
            };
            DB::table($table)->where('id', $id)->update(['company_id' => $other->id]);
        }

        Context::forget('company_id');

        $this->getReport('2026-09')
            ->assertOk()
            ->assertJsonPath('data.total.shifts', 1)
            ->assertJsonPath('data.total.total_pay', 9600)
            ->assertJsonCount(1, 'data.by_worker')
            ->assertJsonPath('data.by_worker.0.worker_id', $clean->application->worker_id)
            ->assertJsonCount(1, 'data.by_location')
            ->assertJsonPath('data.by_location.0.location_id', $clean->application->jobPosting->location_id);

        $rows = self::parseCsv($this->actingAsAdmin()->get('/api/reports/monthly/csv?month=2026-09')->assertOk()->streamedContent());
        $this->assertCount(2, $rows);
        $this->assertSame('自社の人', $rows[1][0]);
    }

    public function test_集計は保存済みの分と金額の合計で式から再計算しない(): void
    {
        // 9:00-17:00 休憩 60 分なら式では 420 分・9,600 円になるが、保存済みの値（わざと式と合わない値）がそのまま合計される
        $worker = Worker::factory()->create();
        $location = Location::factory()->create();
        $this->shift('2026-09-05', $location, $worker)->forceFill(['worked_minutes' => 1, 'wage_pay' => 7, 'total_pay' => 12345])->save();
        $this->shift('2026-09-06', $location, $worker)->forceFill(['worked_minutes' => 2, 'wage_pay' => 8, 'total_pay' => 100])->save();

        $this->getReport('2026-09')
            ->assertOk()
            ->assertJsonPath('data.by_worker.0.worked_minutes', 3)
            ->assertJsonPath('data.by_worker.0.total_pay', 12445)
            ->assertJsonPath('data.by_location.0.worked_minutes', 3)
            ->assertJsonPath('data.by_location.0.total_pay', 12445)
            ->assertJsonPath('data.total', ['shifts' => 2, 'worked_minutes' => 3, 'total_pay' => 12445]);
    }

    public function test_csvは保存済みの賃金と合計を出し交通費は合計と賃金の差(): void
    {
        $this->shift('2026-09-05')->forceFill(['worked_minutes' => 1, 'wage_pay' => 7, 'total_pay' => 12345])->save();

        $rows = self::parseCsv($this->actingAsAdmin()->get('/api/reports/monthly/csv?month=2026-09')->assertOk()->streamedContent());

        $this->assertSame(['2026-09-05', '1', '7', '12338', '12345'], array_slice($rows[1], 2));
    }

    public function test_csvは勤怠がない月はbomとヘッダだけ(): void
    {
        $this->shift('2026-08-31');

        $body = $this->actingAsAdmin()->get('/api/reports/monthly/csv?month=2026-09')->assertOk()->streamedContent();

        $this->assertSame("\xEF\xBB\xBFワーカー名,拠点名,勤務日,労働分,賃金,交通費,合計\n", $body);
    }

    public function test_csvはワーカー名と拠点名のどちらでも4種の先頭文字にクォートが付く(): void
    {
        // 既存のテストと逆の組み合わせ（ワーカー名に @ と -、拠点名に = と +）で、両方の列に 4 文字すべてを当てる
        $this->shift(
            '2026-09-05',
            Location::factory()->create(['name' => '=1+1']),
            Worker::factory()->create(['name' => '@山田']),
        );
        $this->shift(
            '2026-09-06',
            Location::factory()->create(['name' => '+倉庫']),
            Worker::factory()->create(['name' => '-1']),
        );
        // 先頭でなければそのまま
        $this->shift(
            '2026-09-07',
            Location::factory()->create(['name' => '東京=倉庫']),
            Worker::factory()->create(['name' => '山田-太郎']),
        );

        $rows = self::parseCsv($this->actingAsAdmin()->get('/api/reports/monthly/csv?month=2026-09')->assertOk()->streamedContent());

        $this->assertSame(["'@山田", "'=1+1"], array_slice($rows[1], 0, 2));
        $this->assertSame(["'-1", "'+倉庫"], array_slice($rows[2], 0, 2));
        $this->assertSame(['山田-太郎', '東京=倉庫'], array_slice($rows[3], 0, 2));
    }

    public function test_csvのカンマと引用符を含む名前は1セルに収まる(): void
    {
        $this->shift('2026-09-05', Location::factory()->create(['name' => '東京,"第2"倉庫']), Worker::factory()->create(['name' => '佐藤']));

        $rows = self::parseCsv($this->actingAsAdmin()->get('/api/reports/monthly/csv?month=2026-09')->assertOk()->streamedContent());

        $this->assertCount(7, $rows[1]);
        $this->assertSame('東京,"第2"倉庫', $rows[1][1]);
    }

    public function test_csvの行は勤務日の昇順(): void
    {
        $this->shift('2026-09-20');
        $this->shift('2026-09-03');
        $this->shift('2026-09-11');

        $rows = self::parseCsv($this->actingAsAdmin()->get('/api/reports/monthly/csv?month=2026-09')->assertOk()->streamedContent());

        $this->assertSame(['2026-09-03', '2026-09-11', '2026-09-20'], array_column(array_slice($rows, 1), 2));
    }

    public function test_csvも月の境界と深夜跨ぎは勤務日で判定する(): void
    {
        $this->shift('2026-08-31', posting: ['start_time' => '22:00', 'end_time' => '06:00']);
        $this->shift('2026-09-30', posting: ['start_time' => '22:00', 'end_time' => '06:00']);
        $this->shift('2026-10-01');

        $rows = self::parseCsv($this->actingAsAdmin()->get('/api/reports/monthly/csv?month=2026-09')->assertOk()->streamedContent());

        $this->assertSame(['2026-09-30'], array_column(array_slice($rows, 1), 2));
    }

    public function test_csvに勤怠の後にキャンセルされた応募は含まれない(): void
    {
        $this->shift('2026-09-10');
        $cancelled = $this->shift('2026-09-11');
        $cancelled->application->transitionTo(ApplicationStatus::Cancelled, [
            'cancel_reason' => CancelReason::Worker,
            'cancelled_at' => now(),
        ]);

        $rows = self::parseCsv($this->actingAsAdmin()->get('/api/reports/monthly/csv?month=2026-09')->assertOk()->streamedContent());

        $this->assertSame(['2026-09-10'], array_column(array_slice($rows, 1), 2));
    }

    public function test_csvのmonthが不正なら422(): void
    {
        foreach (['2026-13', '2026-9', 'abcd-ef'] as $month) {
            $this->actingAsAdmin()->getJson("/api/reports/monthly/csv?month={$month}")
                ->assertUnprocessable()
                ->assertJsonStructure(['message', 'errors' => ['month']]);
        }
    }

    public function test_monthの範囲外や型違いも422(): void
    {
        foreach (['month=2026-00', 'month=', 'month=abcd-ef', 'month=%EF%BC%92%EF%BC%90%EF%BC%92%EF%BC%96-09', 'month[]=2026-09'] as $query) {
            $this->actingAsAdmin()->getJson("/api/reports/monthly?{$query}")
                ->assertUnprocessable()
                ->assertJsonStructure(['message', 'errors' => ['month']]);
        }
    }
}
