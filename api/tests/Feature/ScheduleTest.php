<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * routes/console.php のスケジュール登録（Rails の whenever の schedule.rb に当たる）を検証する。
 * コマンドは実行せず、登録内容と isDue() だけを見る。
 * https://readouble.com/laravel/13.x/ja/scheduling.html
 */
class ScheduleTest extends TestCase
{
    public function test_horizon_snapshotがスケジュールに登録されている(): void
    {
        // testing でも環境の条件で外れず、ちょうど 1 件だけ登録されている
        $events = $this->eventsFor('horizon:snapshot');
        $this->assertCount(1, $events);

        // isDue() は environments() しか見ず、when() / skip() のフィルタは見ない。
        // 環境をどちらの書き方で絞っても外れることを、ここで別々に確かめる（本番でも動かす設計）
        $event = $events->first();
        $this->assertSame([], $event->environments, 'environments() で環境を絞っていない');
        $this->assertTrue($event->filtersPass($this->app), 'when() / skip() で外れていない');
    }

    public function test_horizon_snapshotは5分ごとに実行される(): void
    {
        $event = $this->eventsFor('horizon:snapshot')->first();
        $this->assertNotNull($event);

        foreach (['10:00', '10:05', '10:55'] as $time) {
            $this->travelTo(CarbonImmutable::parse("2026-10-31 {$time}:00", 'UTC'));
            $this->assertTrue($event->isDue($this->app), "{$time} に実行されるべき");
        }

        foreach (['10:01', '10:04'] as $time) {
            $this->travelTo(CarbonImmutable::parse("2026-10-31 {$time}:00", 'UTC'));
            $this->assertFalse($event->isDue($this->app), "{$time} には実行されないべき");
        }
    }

    public function test_スケジュールに登録したコマンドはすべてartisanに存在する(): void
    {
        // 無いコマンドを積むと schedule:run で落ちる（Telescope の prune を環境で絞っている理由）の再発防止
        $commands = collect(app(Schedule::class)->events())
            ->reject(fn (Event $event) => $event instanceof CallbackEvent)
            ->map(fn (Event $event) => $this->artisanCommandName((string) $event->command));

        $this->assertNotEmpty($commands);
        $available = array_keys(Artisan::all());
        foreach ($commands as $name) {
            $this->assertContains($name, $available, "スケジュールの {$name} が artisan に存在しない");
        }
    }

    public function test_artisanのコマンド名は引数付きの登録からも取り出せる(): void
    {
        // ヘルパーが壊れていると上の 2 本が素通りや誤検知になるので、実際の command の形（PHP のパスと 'artisan' がクォートされる）で確かめる
        $schedule = new Schedule('UTC');
        $event = $schedule->command('horizon:snapshot', ['--queue' => 'default']);

        $this->assertSame('horizon:snapshot', $this->artisanCommandName((string) $event->command));
    }

    /**
     * コマンド名の完全一致で探す（部分一致だと horizon:snapshot-xxx のような別のコマンドも数えてしまう）
     *
     * @return Collection<int, Event>
     */
    private function eventsFor(string $command): Collection
    {
        return collect(app(Schedule::class)->events())
            ->reject(fn (Event $event) => $event instanceof CallbackEvent)
            ->filter(fn (Event $event) => $this->artisanCommandName((string) $event->command) === $command)
            ->values();
    }

    /**
     * `'/path/php' 'artisan' app:send-reminders --opt` から `app:send-reminders` を取り出す
     */
    private function artisanCommandName(string $command): string
    {
        $tokens = preg_split('/\s+/', trim($command));
        $index = array_search("'artisan'", $tokens, true);
        if ($index === false) {
            $index = array_search('artisan', $tokens, true);
        }
        $this->assertNotFalse($index, "artisan のコマンドではない: {$command}");

        return trim($tokens[$index + 1] ?? '', "'\"");
    }
}
