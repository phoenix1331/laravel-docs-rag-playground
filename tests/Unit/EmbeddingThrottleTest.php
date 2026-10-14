<?php

declare(strict_types=1);

use App\Services\Embeddings\EmbeddingThrottle;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake();
    $this->cache = new Repository(new ArrayStore);
});

afterEach(fn () => Sleep::fake(false));

it('spaces requests by the tighter of the two limits', function () {
    $throttle = new EmbeddingThrottle($this->cache, requestsPerMinute: 3, tokensPerMinute: 6000);

    expect($throttle->interval(100))->toBe(20.0)
        ->and($throttle->interval(3000))->toBe(30.0);
});

it('does nothing when no limits are set', function () {
    $throttle = new EmbeddingThrottle($this->cache);

    $throttle->wait(5000);
    $throttle->wait(5000);

    expect($throttle->enabled())->toBeFalse();
    Sleep::assertNeverSlept();
});

it('lets the first request through and makes the next one wait', function () {
    $throttle = new EmbeddingThrottle($this->cache, requestsPerMinute: 3);

    $throttle->wait(100);
    Sleep::assertNeverSlept();

    $throttle->wait(100);
    Sleep::assertSleptTimes(1);
    Sleep::assertSlept(fn ($duration) => $duration->totalMilliseconds > 19_000 && $duration->totalMilliseconds <= 20_000);
});
