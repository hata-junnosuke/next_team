<?php

namespace Database\Factories;

use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * company_id は BelongsToCompany の creating で CurrentCompany::id() が入るので、ここでは指定しない。
 * 他社の拠点を作るときは CurrentCompany::runAs($otherId, fn () => Location::factory()->create())。
 *
 * @extends Factory<Location>
 */
class LocationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->city().'店',
            'address' => fake()->address(),
        ];
    }
}
