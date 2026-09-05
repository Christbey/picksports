<?php

namespace Database\Factories;

use App\Models\DeveloperProduct;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<DeveloperProduct> */
class DeveloperProductFactory extends Factory
{
    protected $model = DeveloperProduct::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'code' => Str::slug($name),
            'name' => Str::title($name),
            'description' => fake()->sentence(),
            'is_active' => true,
            'default_scopes' => ['events:read'],
            'default_limits' => ['requests_per_month' => 1000],
        ];
    }
}
