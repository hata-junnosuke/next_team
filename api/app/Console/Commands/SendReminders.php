<?php

namespace App\Console\Commands;

use App\Enums\ApplicationStatus;
use App\Jobs\SendShiftReminderMail;
use App\Models\Application;
use App\Models\Company;
use App\Support\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * F-22 前日リマインドを積むコマンド（Rails の rake タスクに当たる）。
 *
 * routes/console.php のスケジュールが毎日 config('myteam.reminder_time') に呼ぶ。手でも実行できる:
 *   php artisan app:send-reminders                    # 翌日の勤務
 *   php artisan app:send-reminders --date=2026-10-10  # 指定した日の勤務
 *
 * 企業ごとに CurrentCompany::runAs() で回し、対象日に勤務がある確定済みの応募 1 件ごとに SendShiftReminderMail を mails に積む。
 * 積むときの Context（企業ID）がジョブのペイロードに入り、ワーカーで戻る。**ここではメールを送らない**（送るのはジョブ）。
 * https://readouble.com/laravel/13.x/ja/artisan.html
 * https://readouble.com/laravel/13.x/ja/scheduling.html
 */
#[Signature('app:send-reminders {--date= : 勤務日（Y-m-d）。省略すると翌日}')]
#[Description('翌日（または指定した日）に勤務がある確定済みの応募ごとに、前日リマインドのジョブを積む')]
class SendReminders extends Command
{
    public function handle(): int
    {
        $date = $this->targetDate();
        if ($date === null) {
            $this->error('--date は Y-m-d 形式の日付で指定してください（例: 2026-10-10）。');

            return self::FAILURE;
        }

        $total = 0;
        $failed = false;
        // Company は企業そのもので BelongsToCompany（企業のグローバルスコープ）を持たないので、withoutGlobalScopes() なしで全社を読める。
        // スケジューラにはログインもリクエストもなく企業が決まっていないので、ここで企業ごとに runAs() で切り替える
        foreach (Company::query()->orderBy('id')->pluck('id') as $companyId) {
            // 1 社の失敗（DB の障害・壊れたデータなど）で、後ろの企業のワーカーにリマインドが届かなくならないよう、企業ごとに受け止めて続ける。
            // 例外は report() でログに残し、最後に終了コードを失敗にする（スケジューラの onFailure やログで気づけるように）。
            // runAs() は finally で企業の設定を戻すので、例外の後に前の企業が残らない
            try {
                $total += CurrentCompany::runAs($companyId, fn () => $this->dispatchFor($date));
            } catch (Throwable $e) {
                report($e);
                $failed = true;
                $this->error("企業ID {$companyId} のリマインドを積めませんでした（ログを確認してください）。");
            }
        }

        $this->info("{$date} の勤務の確定応募 {$total} 件のリマインドを積みました（同じ応募のジョブがまだキューにあれば積みません）。");

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * 今の企業（runAs で設定済み）の、対象日に勤務がある確定済みの応募ごとにジョブを積む。対象の応募の件数を返す。
     * Application と whereHas の JobPosting はどちらも企業のグローバルスコープが効くので、他社の応募は混ざらない。
     * 同じ応募のジョブがまだキューにあれば ShouldBeUnique で積まれない（件数には数える）
     */
    private function dispatchFor(string $date): int
    {
        $ids = Application::query()
            ->where('status', ApplicationStatus::Confirmed)
            ->whereHas('jobPosting', fn ($query) => $query->where('work_date', $date)) // work_date は "Y-m-d" で保存（JobPosting）
            ->orderBy('id')
            ->pluck('id');

        foreach ($ids as $id) {
            SendShiftReminderMail::dispatch($id, $date);
        }

        return $ids->count();
    }

    /**
     * 対象日（"Y-m-d"）。--date がなければ翌日（config('app.timezone') = Asia/Tokyo）。形式が違う・存在しない日付なら null
     */
    private function targetDate(): ?string
    {
        $option = $this->option('date');
        if ($option === null) {
            return CarbonImmutable::tomorrow()->toDateString();
        }

        if (! is_string($option) || preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $option) !== 1) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $option);

        // 2026-13-01 や 2026-02-30 は繰り上がって別の日になるので、書き戻して一致しなければ不正とする
        return $date !== null && $date->toDateString() === $option ? $option : null;
    }
}
