<?php

namespace Database\Factories;

use App\Models\DeveloperOrganization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<DeveloperOrganization> */
class DeveloperOrganizationFactory extends Factory
{
    protected $model = DeveloperOrganization::class;

    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numerify('####'),
            'status' => 'active',
        ];
    }
}
