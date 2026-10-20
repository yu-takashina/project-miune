<?php

namespace Database\Factories;

use App\Enums\SitemapStatus;
use App\Models\Sitemap;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Sitemap>
 */
class SitemapFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'url' => fake()->unique()->url(),
            'searchable' => true,
            'status' => SitemapStatus::Pending,
            'error_message' => null,
            'absence_limit' => Sitemap::DEFAULT_ABSENCE_LIMIT,
        ];
    }
}
