<?php

namespace Database\Factories;

use App\Models\CannedReply;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CannedReply>
 */
class CannedReplyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'shortcut' => '/'.fake()->unique()->word(),
            'title' => fake()->words(3, true),
            'body' => 'Hi {{contact.first_name}}, '.fake()->sentence(10),
            'variables' => ['contact.first_name'],
            'is_shared' => true,
            'created_by' => null,
        ];
    }
}
