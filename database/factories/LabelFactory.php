<?php

namespace Database\Factories;

use App\Models\Label;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Label>
 */
class LabelFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => ucfirst(fake()->unique()->word()),
            'color' => fake()->hexColor(),
            'kind' => 'contact',
            'created_by' => null,
        ];
    }

    /**
     * Indicate that the label applies to conversations.
     */
    public function forConversations(): static
    {
        return $this->state(fn (array $attributes) => [
            'kind' => 'conversation',
        ]);
    }
}
