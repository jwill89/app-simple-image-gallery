<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tag;
use App\Models\TagCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tag>
 */
class TagFactory extends Factory
{
    public function definition(): array
    {
        return [
            'category_id' => TagCategory::factory(),
            // Stored lowercased, as the tag endpoints store them.
            'tag_name' => fake()->unique()->words(2, true),
        ];
    }
}
