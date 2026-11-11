<?php

namespace Tests\Feature;

use App\Mail\ApplicationConfirmedMail;
use App\Mail\PostingCancelledMail;
use App\Mail\WorkerInvitationMail;
use App\Models\Company;
use App\Models\JobPosting;
use App\Models\Location;
use App\Support\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ActsAsWorker;
use Tests\TestCase;

/**
 * 要件 4.8 の動線（docs/05 の付録 A.3 の 6 ステップ）を API 経由だけで 1 本通す（docs/01 の 9 章）。
 *
 * - キューは sync のまま（phpunit.xml）。ジョブはその場で動き、メールは Mail::fake() に届く。招待コードは届いた
 *   WorkerInvitationMail に渡された招待から取り、本文に書かれていることを確かめる（DB の暗号文は読まない）
 * - ワーカーは register / login で受け取った本物のトークンを withToken() で付ける。管理者は switchToAdmin()（ActsAsWorker）
 * - 本番のリクエストは企業が空の状態で始まるので、どのリクエストも asAdmin() / asWorker() / asGuest() を通し、
 *   Context の企業と guard が覚えたユーザーを消してから叩く
 * - 今日は 2026-10-01 10:00 に固定。動線の募集は 10/5、比較用の勤務日が過ぎた募集は 9/30
 */
class WorkerAcceptanceScenarioTest extends TestCase
{
    use ActsAsWorker;

    private const COMPANY_CODE = 'SMPK23';

    private const PASSWORD = 'secret-pass-123';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-01 10:00:00'));
        $this->company->forceFill(['name' => '株式会社サンプル', 'code' => self::COMPANY_CODE])->save();

        // 比較用: 他社の今日以降の募集（ワーカーの一覧に出ない）
        $other = Company::factory()->create();
        CurrentCompany::runAs($other->id, fn () => JobPosting::factory()->onDate('2026-10-05')
            ->for(Location::factory()->create(['name' => '他社倉庫']))->create(['job_title' => '他社の募集']));

        Context::forget('company_id');
    }

    public function test_ワーカーアプリの動線6ステップが通る(): void
    {
        Mail::fake();

        // 前準備（管理者）: 拠点と、ピッキングの募集（10/5・定員 2）と、勤務日が過ぎた募集（9/30）を作る
        $locationId = $this->asAdmin()->postJson('/api/locations', ['name' => '東京倉庫', 'address' => '東京都江東区1-1'])
            ->assertCreated()->json('data.id');
        $postingId = $this->createPosting($locationId, '2026-10-05', 'ピッキング');
        $this->createPosting($locationId, '2026-09-30', '過去の仕分け');

        // ---- 1. 管理者がワーカー「山田」を登録する → 山田のメール宛に招待が届く ----
        $yamadaId = $this->registerWorkerAsAdmin('山田太郎', 'yamada@example.com');
        $yamadaCode = $this->invitationCodeSentTo('yamada@example.com');
        // 5 で使う「山田以外のワーカー」も、同じく招待する
        $this->registerWorkerAsAdmin('佐藤花子', 'sato@example.com');
        $satoCode = $this->invitationCodeSentTo('sato@example.com');

        // ---- 2. 山田が初回登録 → ログインした状態。同じコードはもう使えない。ログアウトしても企業コードでログインし直せる ----
        $this->asGuest();
        $firstToken = $this->postJson('/api/worker/register', $this->registerPayload('yamada@example.com', $yamadaCode))
            ->assertCreated()
            ->assertJsonPath('data.worker', [
                'id' => $yamadaId,
                'name' => '山田太郎',
                'email' => 'yamada@example.com',
                'company' => ['id' => $this->company->id, 'name' => '株式会社サンプル', 'code' => self::COMPANY_CODE],
            ])
            ->json('data.token');
        $this->asWorker($firstToken)->getJson('/api/worker/me')->assertOk()->assertJsonPath('data.id', $yamadaId);

        $this->asGuest();
        $this->postJson('/api/worker/register', $this->registerPayload('yamada@example.com', $yamadaCode))
            ->assertUnprocessable()
            ->assertJsonPath('message', '招待コードが正しくないか、有効期限が切れています。')
            ->assertJsonValidationErrors(['code']);

        $this->asWorker($firstToken)->postJson('/api/worker/logout')->assertNoContent();
        $this->asWorker($firstToken)->getJson('/api/worker/me')->assertUnauthorized();

        $this->asGuest();
        $yamada = $this->postJson('/api/worker/login', [
            'company_code' => self::COMPANY_CODE,
            'email' => 'yamada@example.com',
            'password' => self::PASSWORD,
            'device_name' => 'iPhone 16',
        ])
            ->assertOk()
            ->assertJsonPath('data.worker.id', $yamadaId)
            ->json('data.token');
        $this->asWorker($yamada)->getJson('/api/worker/me')->assertOk()->assertJsonPath('data.email', 'yamada@example.com');

        // ---- 3. 山田がピッキングの募集（今日以降）を探して応募 → 管理画面に応募中。もう一度応募すると 422 ----
        // 一覧には自社の募集中・今日以降だけ（9/30 の募集と他社の募集は出ない）
        $this->asWorker($yamada)->getJson('/api/worker/job-postings')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $postingId)
            ->assertJsonPath('data.0.job_title', 'ピッキング')
            ->assertJsonPath('data.0.work_date', '2026-10-05')
            ->assertJsonPath('data.0.location', ['id' => $locationId, 'name' => '東京倉庫'])
            ->assertJsonPath('data.0.remaining', 2)
            ->assertJsonPath('data.0.my_application', null)
            ->assertJsonPath('data.0.can.apply', true);
        // アプリの画面の流れどおり、詳細を開いてから応募する
        $this->asWorker($yamada)->getJson("/api/worker/job-postings/{$postingId}")
            ->assertOk()
            ->assertJsonPath('data.id', $postingId)
            ->assertJsonPath('data.remaining', 2)
            ->assertJsonPath('data.my_application', null)
            ->assertJsonPath('data.can.apply', true);

        $applicationId = $this->applyAsWorker($yamada, $postingId)
            ->assertCreated()
            ->assertJsonPath('data.status', ['value' => 'applied', 'label' => '応募中'])
            ->json('data.id');

        $this->asAdmin()->getJson("/api/job-postings/{$postingId}/applications")
            ->assertOk()
            ->assertJsonPath('data.*.id', [$applicationId])
            ->assertJsonPath('data.0.worker.name', '山田太郎')
            ->assertJsonPath('data.0.status', ['value' => 'applied', 'label' => '応募中']);

        $this->applyAsWorker($yamada, $postingId)
            ->assertUnprocessable()
            ->assertExactJson(['message' => 'この募集にはすでに応募しています。', 'errors' => []]);

        // ---- 4. 管理者が山田を確定 → 山田の応募一覧に確定で出る。確定通知メールも届く（F-20） ----
        $this->asAdmin()->postJson("/api/applications/{$applicationId}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status.value', 'confirmed');
        Mail::assertSent(ApplicationConfirmedMail::class, 1);
        Mail::assertSent(ApplicationConfirmedMail::class, fn ($mail) => $mail->hasTo('yamada@example.com'));

        $this->asWorker($yamada)->getJson('/api/worker/applications')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$applicationId])
            ->assertJsonPath('data.0.job_posting.id', $postingId)
            ->assertJsonPath('data.0.status', ['value' => 'confirmed', 'label' => '確定'])
            ->assertJsonPath('data.0.confirmed_hourly_wage', 1300)
            ->assertJsonPath('data.0.confirmed_transport_allowance', 500);

        // ---- 5. 管理者が定員まで確定 → 山田以外（佐藤）のアプリで残り枠 0、応募できない ----
        // 2 人目は管理者の代行入力（F-09）で応募させて確定する（登録はアプリの外でよい）
        $takahashiId = $this->registerWorkerAsAdmin('高橋次郎', 'takahashi@example.com');
        $takahashiApplicationId = $this->asAdmin()->postJson("/api/job-postings/{$postingId}/applications", ['worker_id' => $takahashiId])
            ->assertCreated()->json('data.id');
        $this->asAdmin()->postJson("/api/applications/{$takahashiApplicationId}/confirm")->assertOk();
        $this->asAdmin()->getJson("/api/job-postings/{$postingId}")->assertJsonPath('data.confirmed_count', 2);

        $this->asGuest();
        $sato = $this->postJson('/api/worker/register', $this->registerPayload('sato@example.com', $satoCode))
            ->assertCreated()->json('data.token');
        $this->asWorker($sato)->getJson('/api/worker/job-postings')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$postingId])
            ->assertJsonPath('data.0.remaining', 0)
            ->assertJsonPath('data.0.my_application', null)
            ->assertJsonPath('data.0.can.apply', false);
        $this->asWorker($sato)->getJson("/api/worker/job-postings/{$postingId}")
            ->assertOk()
            ->assertJsonPath('data.remaining', 0)
            ->assertJsonPath('data.my_application', null)
            ->assertJsonPath('data.can.apply', false);
        $this->applyAsWorker($sato, $postingId)
            ->assertUnprocessable()
            ->assertExactJson(['message' => '残り枠がないため応募できません。', 'errors' => []]);
        $this->asWorker($sato)->getJson('/api/worker/applications')->assertOk()->assertJsonPath('data', []);

        // ---- 6. 管理者が募集を中止 → 山田の応募一覧に「キャンセル（企業都合）」で出る（F-07） ----
        $this->asAdmin()->postJson("/api/job-postings/{$postingId}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status.value', 'cancelled');
        // 山田宛ての中止のメールは 1 通
        Mail::assertSent(PostingCancelledMail::class, fn ($mail) => $mail->hasTo('yamada@example.com'));
        $this->assertCount(1, Mail::sent(PostingCancelledMail::class, fn ($mail) => $mail->hasTo('yamada@example.com')));
        // 山田宛ての確定通知もステップ 4 の 1 通のまま（中止で増えない）
        $this->assertCount(1, Mail::sent(ApplicationConfirmedMail::class, fn ($mail) => $mail->hasTo('yamada@example.com')));

        $this->asWorker($yamada)->getJson('/api/worker/applications')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$applicationId])
            ->assertJsonPath('data.0.status', ['value' => 'cancelled', 'label' => 'キャンセル'])
            ->assertJsonPath('data.0.cancel_reason', ['value' => 'company', 'label' => '企業都合'])
            ->assertJsonPath('data.0.cancelled_at', '2026-10-01T10:00:00+09:00');
        // 中止した募集は一覧から消えるが、自分の応募がある詳細は見られる
        $this->asWorker($yamada)->getJson('/api/worker/job-postings')->assertOk()->assertJsonPath('data', []);
        $this->asWorker($yamada)->getJson("/api/worker/job-postings/{$postingId}")
            ->assertOk()
            ->assertJsonPath('data.status.value', 'cancelled')
            ->assertJsonPath('data.my_application', ['id' => $applicationId, 'status' => ['value' => 'cancelled', 'label' => 'キャンセル']])
            ->assertJsonPath('data.can.apply', false);
    }

    // ---- 補助 ----

    /** 管理者として次のリクエストを送る（ワーカーのトークンを外し、guard を web に戻す） */
    private function asAdmin(): static
    {
        Context::forget('company_id');

        return $this->withoutToken()->switchToAdmin();
    }

    /** ワーカーのアプリとして、本物のトークンを付けて次のリクエストを送る */
    private function asWorker(string $token): static
    {
        Context::forget('company_id');
        $this->forgetAuthenticatedUsers();

        return $this->withToken($token);
    }

    /** ログインしていないアプリ（register / login の前） */
    private function asGuest(): static
    {
        Context::forget('company_id');
        $this->forgetAuthenticatedUsers();

        return $this->withoutToken();
    }

    private function createPosting(int $locationId, string $workDate, string $jobTitle): int
    {
        return $this->asAdmin()->postJson('/api/job-postings', [
            'location_id' => $locationId,
            'job_title' => $jobTitle,
            'work_date' => $workDate,
            'start_time' => '09:00',
            'end_time' => '17:00',
            'capacity' => 2,
            'hourly_wage' => 1300,
            'transport_allowance' => 500,
        ])->assertCreated()->json('data.id');
    }

    private function registerWorkerAsAdmin(string $name, string $email): int
    {
        return $this->asAdmin()
            ->postJson('/api/workers', ['name' => $name, 'email' => $email, 'phone' => null])
            ->assertCreated()
            ->json('data.id');
    }

    /**
     * 宛先に 1 通だけ届いた招待メールから招待コードを取り出す。コードはメールに渡された招待（組み立てる前の値）から読み、
     * 本文に招待コードと企業コードが書かれていることを確かめる。登録の後は暗号化した列が消えるので、届いた直後に呼ぶ
     */
    private function invitationCodeSentTo(string $email): string
    {
        $mail = Mail::sent(WorkerInvitationMail::class, fn (WorkerInvitationMail $mail) => $mail->hasTo($email))->sole();
        $code = $mail->invitation->code;
        $this->assertMatchesRegularExpression('/\A[A-Z0-9]{8}\z/', $code);

        $mail->assertSeeInHtml($code);
        $mail->assertSeeInHtml(self::COMPANY_CODE);

        return $code;
    }

    /** @return array<string, string> */
    private function registerPayload(string $email, string $code): array
    {
        return [
            'email' => $email,
            'code' => $code,
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'device_name' => 'iPhone 16',
        ];
    }

    private function applyAsWorker(string $token, int $postingId): TestResponse
    {
        return $this->asWorker($token)->postJson("/api/worker/job-postings/{$postingId}/applications");
    }
}
