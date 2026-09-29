<?php

namespace Database\Factories;

use App\Models\Position;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Position>
 */
class PositionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->numerify('####'),
            'name' => 'ตำแหน่ง '.fake()->unique()->word(),
            'is_active' => true,
        ];
    }

    public function fromOracle(?string $externalId = null): static
    {
        return $this->state(fn (array $attributes) => [
            'external_id' => $externalId ?? $attributes['code'],
            'synced_at' => now(),
        ]);
    }
}
