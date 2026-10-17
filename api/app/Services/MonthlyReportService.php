<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Models\Attendance;
use App\Support\CurrentCompany;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use InvalidArgumentException;

/**
 * 月次集計（F-17）と CSV の行（F-18。非同期出力 F-23 でも使う）。docs/01 4.5・4.6・6.7。
 *
 * - 対象は「募集の勤務日（job_postings.work_date）が対象月に入る」かつ「応募が確定」の勤怠（6.6）
 * - 月の範囲は半開区間（>= 月初 AND < 翌月初）。work_date は "Y-m-d" の文字列で保存しているので文字列で比べる。
 *   whereBetween（月末日が漏れる）と whereDate（索引が効かない）は使わない
 * - 集計は SQL の join + GROUP BY + SUM。attendances に保存済みの金額・分を合計する
 * - グローバルスコープが効くのは起点の attendances だけなので、join 先にも company_id 条件を明示する
 * - 並びは ID 順（登録順）。日本語の氏名・拠点名を SQL で並べても文字コード順で読みの順にならないため
 */
class MonthlyReportService
{
    /** CSV のヘッダ（F-18。列の順は csvRows() と同じ） */
    public const CSV_HEADER = ['ワーカー名', '拠点名', '勤務日', '労働分', '賃金', '交通費', '合計'];

    /** 勤怠の件数と、保存済みの労働分・合計の和（勤怠が 0 件の月でも SUM が NULL にならないよう 0 にする） */
    private const AGGREGATES = 'COUNT(*) as shifts, COALESCE(SUM(attendances.worked_minutes), 0) as worked_minutes, COALESCE(SUM(attendances.total_pay), 0) as total_pay';

    public function build(string $month): MonthlyReport
    {
        $byWorker = $this->baseQuery($month)
            ->groupBy('workers.id', 'workers.name')
            ->orderBy('workers.id')
            ->select('workers.id as worker_id', 'workers.name')
            ->selectRaw(self::AGGREGATES)
            ->get()
            ->map(fn (object $row) => [
                'worker_id' => (int) $row->worker_id,
                'name' => (string) $row->name,
                ...self::sums($row),
            ])
            ->all();

        $byLocation = $this->baseQuery($month)
            ->groupBy('locations.id', 'locations.name')
            ->orderBy('locations.id')
            ->select('locations.id as location_id', 'locations.name')
            ->selectRaw(self::AGGREGATES)
            ->get()
            ->map(fn (object $row) => [
                'location_id' => (int) $row->location_id,
                'name' => (string) $row->name,
                ...self::sums($row),
            ])
            ->all();

        // 合計も SQL で集計する（PHP 側で足し合わせない。docs/01 4.6）
        $total = $this->baseQuery($month)
            ->selectRaw(self::AGGREGATES)
            ->first();
        $totals = self::sums($total);

        return new MonthlyReport(
            month: $month,
            byWorker: $byWorker,
            byLocation: $byLocation,
            totalShifts: $totals['shifts'],
            totalWorkedMinutes: $totals['worked_minutes'],
            totalPay: $totals['total_pay'],
        );
    }

    /**
     * CSV の行（ヘッダなし。列は CSV_HEADER）。cursor() で 1 行ずつ読むので、件数が多くてもメモリに全件を載せない。
     * 文字列のセルは CSV インジェクション対策済み（escapeCell()）。
     *
     * このメソッド自体はジェネレータにしない。企業 ID（CurrentCompany::id()）と月の範囲の検査をここで先に済ませ、
     * 失敗（企業が未設定・月の書式違い）をストリームを書き始める前の例外として出す。
     * ジェネレータだと最初の行を読むまで本体が動かず、200 のヘッダを送った後で落ちる。
     * https://www.php.net/manual/ja/language.generators.overview.php
     *
     * @return iterable<int, list<string|int>>
     */
    public function csvRows(string $month, ?int $locationId = null): iterable
    {
        $query = $this->baseQuery($month)
            ->when($locationId !== null, fn (Builder $q) => $q->where('locations.id', $locationId))
            ->orderBy('job_postings.work_date')
            ->orderBy('locations.id')
            ->orderBy('workers.id')
            ->orderBy('attendances.id')
            ->select(
                'workers.name as worker_name',
                'locations.name as location_name',
                'job_postings.work_date',
                'attendances.worked_minutes',
                'attendances.wage_pay',
                'attendances.total_pay',
            );

        return self::rows($query);
    }

    /**
     * その月に集計対象の勤怠がある自社の拠点 ID（昇順）。非同期出力（F-23）で拠点ごとにジョブを分けるのに使う
     *
     * @return list<int>
     */
    public function locationIds(string $month): array
    {
        return $this->baseQuery($month)
            ->distinct()
            ->orderBy('locations.id')
            ->pluck('locations.id')
            ->map(fn (mixed $id) => (int) $id)
            ->all();
    }

    /**
     * @return Generator<int, list<string|int>>
     */
    private static function rows(Builder $query): Generator
    {
        foreach ($query->cursor() as $row) {
            yield [
                self::escapeCell((string) $row->worker_name),
                self::escapeCell((string) $row->location_name),
                (string) $row->work_date,
                (int) $row->worked_minutes,
                (int) $row->wage_pay,
                // 交通費 = 合計 - 賃金（保存時に「合計 = 賃金 + 交通費」で確定している。6.6）
                (int) $row->total_pay - (int) $row->wage_pay,
                (int) $row->total_pay,
            ];
        }
    }

    /**
     * 表計算ソフトで数式として解釈される先頭文字（= + - @ とタブ・CR）なら、先頭に ' を付けて文字列として扱わせる（docs/01 4.6、OWASP の CSV Injection）。
     * 全角の ＝ ＋ － ＠ は表計算ソフトが数式の開始として扱わないので対象にしない
     */
    public static function escapeCell(string $value): string
    {
        return preg_match('/\A[=+\-@\t\r]/', $value) === 1 ? "'{$value}" : $value;
    }

    /**
     * 集計と CSV に共通の母集団: 自社の、確定した応募の、勤務日が対象月の勤怠
     */
    private function baseQuery(string $month): Builder
    {
        [$from, $until] = self::range($month);
        $companyId = CurrentCompany::id();

        // 起点の attendances は Eloquent のグローバルスコープで company_id が付く（toBase() はスコープを適用した後の Query Builder を返す）。
        // join 先にはスコープが効かないので、それぞれ company_id を明示する（docs/01 4.6）
        return Attendance::query()->toBase()
            ->join('applications', fn (JoinClause $join) => $join
                ->on('applications.id', '=', 'attendances.application_id')
                ->where('applications.company_id', '=', $companyId))
            ->join('job_postings', fn (JoinClause $join) => $join
                ->on('job_postings.id', '=', 'applications.job_posting_id')
                ->where('job_postings.company_id', '=', $companyId))
            ->join('workers', fn (JoinClause $join) => $join
                ->on('workers.id', '=', 'applications.worker_id')
                ->where('workers.company_id', '=', $companyId))
            ->join('locations', fn (JoinClause $join) => $join
                ->on('locations.id', '=', 'job_postings.location_id')
                ->where('locations.company_id', '=', $companyId))
            ->where('applications.status', ApplicationStatus::Confirmed->value)
            ->where('job_postings.work_date', '>=', $from)
            ->where('job_postings.work_date', '<', $until);
    }

    /**
     * "2026-09" → ["2026-09-01", "2026-10-01"]（半開区間の両端。"Y-m-d" の文字列）。
     * 書式の先頭の ! で日・時刻を 0 に揃える（今日の日付が 31 日でも月がずれない）
     * https://www.php.net/manual/ja/datetimeimmutable.createfromformat.php
     *
     * @return array{string, string}
     */
    private static function range(string $month): array
    {
        $start = CarbonImmutable::createFromFormat('!Y-m', $month);

        if ($start === null || $start->format('Y-m') !== $month) {
            throw new InvalidArgumentException("月は YYYY-MM で渡してください（{$month}）。");
        }

        return [$start->toDateString(), $start->addMonthNoOverflow()->toDateString()];
    }

    /**
     * @return array{shifts: int, worked_minutes: int, total_pay: int}
     */
    private static function sums(object $row): array
    {
        return [
            'shifts' => (int) $row->shifts,
            'worked_minutes' => (int) $row->worked_minutes,
            'total_pay' => (int) $row->total_pay,
        ];
    }
}
