<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Media;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Media>
 */
class MediaFactory extends Factory
{
    public function definition(): array
    {
        return [
            'media_type' => 'image',
            'file_name' => fake()->unique()->slug(3) . '.' . fake()->randomElement(['jpg', 'png', 'webp']),
            'file_time' => fake()->dateTimeBetween('-2 years')->getTimestamp(),
            'hash' => fake()->md5(),
            'bits_fingerprint' => self::fingerprint(),
            'width' => fake()->numberBetween(200, 4000),
            'height' => fake()->numberBetween(200, 4000),
            'duration' => 0.0,
            'file_size' => fake()->numberBetween(10_000, 10_000_000),
        ];
    }

    /** A video: no perceptual fingerprint, and a duration. */
    public function video(): static
    {
        return $this->state(fn(): array => [
            'media_type' => 'video',
            'file_name' => fake()->unique()->slug(3) . '.mp4',
            'bits_fingerprint' => '',
            'duration' => fake()->randomFloat(2, 1, 600),
        ]);
    }

    /** A 64-bit perceptual hash, as the string of 0s and 1s media.bits_fingerprint stores. */
    private static function fingerprint(): string
    {
        return implode('', array_map(static fn(): string => (string) fake()->numberBetween(0, 1), range(1, 64)));
    }
}
