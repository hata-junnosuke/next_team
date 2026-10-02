<?php

namespace Tests\Feature;

use App\Models\ReportExport;
use App\Providers\TelescopeServiceProvider;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Laravel\Telescope\Http\Middleware\Authorize;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\Watchers\RequestWatcher;
use Tests\TestCase;

/**
 * /horizon と /telescope の入口（security.md: ローカル環境 かつ ログイン済みの管理者だけ）。
 * テストの環境は testing なので、ローカルの場面では app()['env'] を local に差し替える。
 * Horizon::auth() / Telescope::auth() のコールバックはリクエストのたびに環境を見るので、差し替えはリクエストの前でよい。
 * https://readouble.com/laravel/13.x/ja/horizon.html#dashboard-authorization
 */
class HorizonAccessTest extends TestCase
{
    /** @var array<string, mixed> TelescopeServiceProvider が書き換える Telescope の static（テストごとに元に戻す） */
    private array $telescopeStatics = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->telescopeStatics = [
            'hiddenRequestHeaders' => Telescope::$hiddenRequestHeaders,
            'hiddenRequestParameters' => Telescope::$hiddenRequestParameters,
            'hiddenResponseParameters' => Telescope::$hiddenResponseParameters,
            'afterRecordingHook' => Telescope::$afterRecordingHook,
        ];
    }

    protected function tearDown(): void
    {
        // static はプロセスの間残るので、後続のテスト（ほかのクラスを含む）に設定を持ち越さない
        foreach ($this->telescopeStatics as $name => $value) {
            Telescope::${$name} = $value;
        }

        parent::tearDown();
    }

    private function actAsLocal(): void
    {
        $this->app['env'] = 'local';
    }

    public function test_未ログインでhorizonを開くと403(): void
    {
        $this->actAsLocal();

        $this->get('/horizon')->assertForbidden();
        $this->getJson('/horizon/api/stats')->assertForbidden();
    }

    public function test_ログイン済み管理者はhorizonを開ける(): void
    {
        $this->actAsLocal();

        $this->actingAsAdmin()->get('/horizon')->assertOk();
    }

    public function test_ログイン済み管理者はローカルでhorizonのapiを開ける(): void
    {
        $this->actAsLocal();
        // Horizon の API は Redis を読む（/horizon/api/stats は Redis が無いと 500）。入口の判定だけを確かめたいので、
        // Redis を読まずに済む /horizon/api/masters を選び、その読み込み先（契約インターフェース）を空を返す偽物に差し替える
        $this->mock(MasterSupervisorRepository::class, fn ($mock) => $mock->shouldReceive('all')->andReturn([]));
        $this->mock(SupervisorRepository::class, fn ($mock) => $mock->shouldReceive('all')->andReturn([]));

        $this->actingAsAdmin()->getJson('/horizon/api/masters')->assertOk();
    }

    public function test_ローカル以外では管理者でもhorizonは403(): void
    {
        $this->assertFalse($this->app->environment('local'));

        $this->actingAsAdmin()->get('/horizon')->assertForbidden();
        $this->actingAsAdmin()->getJson('/horizon/api/stats')->assertForbidden();
    }

    public function test_telescopeはローカルのログイン済み管理者だけが見られる(): void
    {
        // Telescope は local でだけ登録する（AppServiceProvider::register()）ので、テストでは入口の判定だけを確かめる
        $this->app->register(TelescopeServiceProvider::class);

        $guest = Request::create('/telescope');
        $admin = Request::create('/telescope');
        $admin->setUserResolver(fn () => $this->admin);

        $this->assertFalse(Telescope::check($admin), 'ローカル以外では管理者でも見られない');

        $this->actAsLocal();
        $this->assertFalse(Telescope::check($guest), '未ログインは見られない');
        $this->assertTrue(Telescope::check($admin), 'ローカルのログイン済み管理者は見られる');
    }

    public function test_telescopeはcookieとcsrfとパスワードを記録しない(): void
    {
        $this->app->register(TelescopeServiceProvider::class);

        foreach (['cookie', 'set-cookie', 'x-csrf-token', 'x-xsrf-token', 'authorization'] as $header) {
            $this->assertContains($header, Telescope::$hiddenRequestHeaders, "ヘッダ {$header} を隠す");
        }
        foreach (['password', 'password_confirmation', '_token', 'password_hash_web', 'password_hash_sanctum'] as $parameter) {
            $this->assertContains($parameter, Telescope::$hiddenRequestParameters, "パラメータ {$parameter} を隠す");
        }
        $this->assertContains(Authorize::class, config('telescope.middleware'), '画面の入口で Telescope::check() を通す');
    }

    public function test_telescopeは署名付きurlを記録しない(): void
    {
        $this->app->register(TelescopeServiceProvider::class);

        $this->assertContains('data.download_url', Telescope::$hiddenResponseParameters, '詳細の download_url を隠す');
        $this->assertContains('signature', Telescope::$hiddenRequestParameters, 'ダウンロードのクエリの署名を隠す');
        $this->assertNotNull(Telescope::$afterRecordingHook, '一覧の download_url と URI の署名は記録の後に隠す');
    }

    public function test_telescopeのリクエストの記録に署名付きurlが残らない(): void
    {
        // 設定に入っただけでなく、RequestWatcher の整形（hideParameters）と記録の後の加工を実際に通して確かめる。
        // Telescope は testing では登録しないので、watcher を直接作って RequestHandled を渡す
        $this->app->register(TelescopeServiceProvider::class);
        $export = ReportExport::factory()->completed()->create();
        ReportExport::factory()->create(['month' => '2026-01']);

        $index = $this->actingAsAdmin()->getJson('/api/report-exports')->assertOk();
        $show = $this->actingAsAdmin()->getJson("/api/report-exports/{$export->id}")->assertOk();
        $downloadUrl = $show->json('data.download_url');
        $this->assertStringContainsString('signature=', $downloadUrl);
        $download = Request::create($downloadUrl);
        // ブラウザから開くと StartSession が storeCurrentUrl() で署名入りの fullUrl() を _previous.url に入れる。その状態を作る
        $session = new Store('test', new ArraySessionHandler(120));
        $session->put('_previous.url', $download->fullUrl());
        $download->setLaravelSession($session);

        $watcher = new RequestWatcher([]);
        Telescope::flushEntries();
        Telescope::startRecording(loadMonitoredTags: false); // 監視タグは DB（telescope_monitoring）を読むので読まない
        try {
            $watcher->recordRequest(new RequestHandled($index->baseRequest, $index->baseResponse));
            $watcher->recordRequest(new RequestHandled($show->baseRequest, $show->baseResponse));
            $watcher->recordRequest(new RequestHandled($download, new Response('csv', 200, ['Content-Type' => 'text/csv'])));
            $entries = array_map(fn (IncomingEntry $entry) => $entry->content, Telescope::$entriesQueue);
        } finally {
            Telescope::stopRecording();
            Telescope::flushEntries();
        }

        $this->assertCount(3, $entries);
        [$indexEntry, $showEntry, $downloadEntry] = $entries;

        $urls = array_column($indexEntry['response']['data'], 'download_url', 'id');
        $this->assertSame('********', $urls[$export->id], '一覧の完了した出力の URL を隠す');
        $this->assertContains(null, $urls, '未完了の出力の null はそのまま');
        $this->assertSame('********', $showEntry['response']['data']['download_url'], '詳細の URL を隠す');
        $this->assertSame($export->id, $showEntry['response']['data']['id'], 'ほかの項目は残す');

        $signature = $download->query('signature');
        $this->assertSame('********', $downloadEntry['payload']['signature'], 'ダウンロードのクエリの署名を隠す');
        $this->assertStringNotContainsString($signature, $downloadEntry['uri'], 'ダウンロードの URI に署名を残さない');
        $this->assertStringContainsString('signature=********', $downloadEntry['uri']);
        $this->assertStringNotContainsString($signature, $downloadEntry['session']['_previous']['url'], 'セッションの直前の URL に署名を残さない');
        $this->assertStringContainsString('signature=********', $downloadEntry['session']['_previous']['url']);

        $this->assertStringNotContainsString($signature, json_encode($entries), 'どこにも署名が残らない');
        $this->assertStringNotContainsString('signature=', json_encode([$indexEntry, $showEntry]));
    }

    public function test_testing環境ではtelescopeを登録しない(): void
    {
        $this->assertFalse($this->app->providerIsLoaded(\Laravel\Telescope\TelescopeServiceProvider::class));
    }
}
