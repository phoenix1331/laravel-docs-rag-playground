<?php

declare(strict_types=1);

namespace Tests;

use App\Contracts\Embedder;
use App\Services\Embeddings\FakeEmbedder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Ai\Embeddings;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // No test may reach a real embeddings API.
        $this->app->instance(Embedder::class, new FakeEmbedder(config('rag.embedding.dimensions')));
        Embeddings::fake()->preventStrayEmbeddings();
    }
}
