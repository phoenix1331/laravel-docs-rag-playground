<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Contracts\Embedder;
use App\Models\DocChunk;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DocChunk>
 */
class DocChunkFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $version = '13.x';
        $page = fake()->randomElement(['queues', 'routing', 'eloquent', 'validation']);
        $heading = Str::title(fake()->unique()->words(3, true));
        $anchor = Str::slug($heading);
        $content = fake()->paragraphs(3, true);

        return [
            'version' => $version,
            'file' => "{$page}.md",
            'heading' => $heading,
            'heading_path' => Str::title($page)." > {$heading}",
            'anchor' => $anchor,
            'url' => "https://laravel.com/docs/{$version}/{$page}#{$anchor}",
            'content' => $content,
            'token_count' => (int) ceil(strlen($content) / 4),
            'hash' => hash('sha256', $content),
            'embedding_model' => app(Embedder::class)->model(),
            'embedding' => $this->randomUnitVector(config('rag.embedding.dimensions')),
        ];
    }

    /**
     * Set the docs version and rebuild the URL to match.
     */
    public function version(string $version): static
    {
        return $this->state(fn (array $attributes) => [
            'version' => $version,
            'url' => preg_replace('#/docs/[^/]+/#', "/docs/{$version}/", $attributes['url']),
        ]);
    }

    /**
     * Build a random vector normalised to unit length.
     *
     * @return array<int, float>
     */
    protected function randomUnitVector(int $dimensions): array
    {
        $vector = array_map(fn () => fake()->randomFloat(6, -1, 1), range(1, $dimensions));
        $length = sqrt(array_sum(array_map(fn (float $value) => $value ** 2, $vector))) ?: 1.0;

        return array_map(fn (float $value) => $value / $length, $vector);
    }
}
