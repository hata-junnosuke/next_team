<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Exceptions\DomainException;
use App\Models\Application;
use App\Models\Attendance;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * 勤怠の保存（F-15、F-16、docs/01 のサービス契約・業務ルールの決定）。
 * 深夜跨ぎ・同時刻・休憩の判定はここの 1 箇所だけに置く（FormRequest と web には書かない）。
 * 応募の状態は読むだけで変えない（状態を変えるのは担当 B の ApplicationService）。
 */
class AttendanceService
{
    public function __construct(private readonly PayrollCalculator $calculator) {}

    /**
     * 確定済みの応募の勤怠を作成または更新し、労働分・賃金・合計を保存時に確定する。
     *
     * @param  string  $clockIn  "HH:MM"（FormRequest で検証済み）
     * @param  string  $clockOut  "HH:MM"。出勤より前（または同じ日の 0 時をまたぐ）なら翌日の時刻として扱う
     */
    public function save(Application $application, string $clockIn, string $clockOut, int $breakMinutes): Attendance
    {
        return DB::transaction(function () use ($application, $clockIn, $clockOut, $breakMinutes) {
            // ルートモデルバインディングで読んだ後に、別のリクエストがキャンセルしているかもしれない。
            // トランザクションの中で読み直してから状態を見る（SQLite の IMMEDIATE で直列化される）
            $application->refresh();

            if ($application->status !== ApplicationStatus::Confirmed) {
                throw new DomainException('確定済みの応募にだけ勤怠を入力できます。');
            }

            if ($clockIn === $clockOut) {
                throw new DomainException('出勤と退勤が同じ時刻です。', field: 'clock_out');
            }

            // 日付は募集の勤務日から組み立てる。タイムゾーンは config('app.timezone')（Asia/Tokyo）
            $workDate = $application->jobPosting->work_date->toDateString();
            $in = self::at($workDate, $clockIn);
            $out = self::at($workDate, $clockOut);

            // 退勤 ≤ 出勤なら翌日扱い（同時刻は上で弾いているので、ここに来るのは退勤 < 出勤だけ）
            if ($out->lessThan($in)) {
                $out = $out->addDay();
            }

            $spanMinutes = (int) $in->diffInMinutes($out);

            if ($breakMinutes > $spanMinutes) {
                throw new DomainException('休憩が労働時間を超えています。', field: 'break_minutes');
            }

            // 確定済みなら transitionTo() が時給・交通費のコピーを必須にしているので、ここで null なのはデータの不整合。
            // 0 円として黙って計算すると報酬が 0 で確定してしまうので、422 ではなく LogicException（500）で止める
            if ($application->confirmed_hourly_wage === null || $application->confirmed_transport_allowance === null) {
                throw new LogicException("確定済みの応募 {$application->id} に確定時の時給または交通費がありません（確定処理が transitionTo() を通っていない可能性があります）。");
            }

            $payroll = $this->calculator->calculate(
                $spanMinutes - $breakMinutes,
                $application->confirmed_hourly_wage,
                $application->confirmed_transport_allowance,
            );

            // 応募と 1 対 1。なければ作り、あれば更新する（firstOrNew が application_id を埋める）
            $attendance = $application->attendance()->firstOrNew();
            $attendance->fill([
                'clock_in' => $in,
                'clock_out' => $out,
                'break_minutes' => $breakMinutes,
            ]);
            // 金額 3 列は Fillable ではない（リクエストの値で書き換えさせない）ので forceFill で入れる
            $attendance->forceFill([
                'worked_minutes' => $payroll->workedMinutes,
                'wage_pay' => $payroll->wagePay,
                'total_pay' => $payroll->totalPay,
            ])->save();

            return $attendance;
        });
    }

    /**
     * "Y-m-d" と "HH:MM" から日時を作る。書式の先頭の ! で、指定しない秒などを現在時刻ではなく 0 にする
     * https://www.php.net/manual/ja/datetimeimmutable.createfromformat.php
     */
    private static function at(string $date, string $time): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d H:i', "{$date} {$time}", config('app.timezone'));
    }
}
