<?php

namespace Database\Factories;

use App\Models\Worker;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * company_id は BelongsToCompany の creating で CurrentCompany::id() が入るので、ここでは指定しない。
 * 他社のワーカーは CurrentCompany::runAs($otherId, fn () => Worker::factory()->create())。
 *
 * @extends Factory<Worker>
 */
class WorkerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->optional()->numerify('090-####-####'),
            'is_active' => true,
        ];
    }

    /**
     * 無効のワーカー（F-03。新規応募できない）
     */
    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    /**
     * 初回登録を済ませたワーカー（パスワードは password。login_email は email を小文字にしたもの。docs/01 の 3.1）
     */
    public function registered(): static
    {
        // login_email は属性のクロージャにする（create(['email' => ...]) で渡したメールを見てから決まるように）
        return $this->state([
            'password' => 'password',
            'login_email' => fn (array $attributes) => strtolower($attributes['email']),
            'registered_at' => now(),
        ]);
    }
}
