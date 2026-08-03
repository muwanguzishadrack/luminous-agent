<?php

namespace Database\Factories;

use App\Models\TemplateGroup;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TemplateGroup>
 */
class TemplateGroupFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->word().' '.fake()->word();

        return [
            'key' => Str::snake($name),
            'name' => Str::title($name),
        ];
    }
}
