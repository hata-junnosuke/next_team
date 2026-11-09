<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Worker;
use App\Models\WorkerInvitation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * 既定は「今の企業の未登録のワーカーへの、使える招待（期限は設定の日数後）」。
 * company_id は BelongsToCompany の creating で CurrentCompany::id() が入る。ワーカーも同じ企業で作る
 * （worker_id の Worker::factory() は今の企業で作られる）。
 * code を指定すると code_hash はそのコードから作る。使用済み・取り消しはコードが NULL なので、ハッシュは別の乱数のコードから作る。used_at・revoked_at はファクトリでは入れられる（Fillable 外でも unguarded で作るため）。
 *
 * @extends Factory<WorkerInvitation>
 */
class WorkerInvitationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'worker_id' => Worker::factory(),
            'code' => self::randomCode(),
            'code_hash' => fn (array $attributes) => WorkerInvitation::hashCode($attributes['code']),
            'expires_at' => now()->addDays((int) config('myteam.worker_invitation_days')),
        ];
    }

    /**
     * 招待コードの形（紛らわしい文字を除いた 8 文字。docs/01 の 3.2）。本物の発行は WorkerInvitationService
     */
    private static function randomCode(): string
    {
        return fake()->unique()->regexify('['.Company::CODE_CHARACTERS.']{8}');
    }

    /**
     * 使用済み（初回登録を済ませた。コードは消える）
     */
    public function used(): static
    {
        return $this->state([
            'code_hash' => fn () => WorkerInvitation::hashCode(self::randomCode()),
            'code' => null,
            'used_at' => now(),
        ]);
    }

    /**
     * 取り消し済み（送り直した。コードは消える）
     */
    public function revoked(): static
    {
        return $this->state([
            'code_hash' => fn () => WorkerInvitation::hashCode(self::randomCode()),
            'code' => null,
            'revoked_at' => now(),
        ]);
    }

    /**
     * 期限切れ
     */
    public function expired(): static
    {
        return $this->state(['expires_at' => now()->subMinute()]);
    }
}
