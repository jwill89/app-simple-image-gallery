<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\TagCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TagCategory>
 */
class TagCategoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'category_name' => ucfirst(fake()->unique()->word()),
            'color' => fake()->randomElement(TagCategory::COLORS),
            'description' => fake()->sentence(),
            'sort_order' => fake()->numberBetween(0, 10),
        ];
    }
}
