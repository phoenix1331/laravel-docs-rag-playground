<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Answering\DocsPrompt;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

class DocsAgent implements Agent, HasProviderOptions
{
    use Promptable;

    public function __construct(
        protected string $version = '13.x',
        protected int $maxTokens = 4096,
    ) {}

    public function instructions(): string
    {
        return DocsPrompt::instructions($this->version);
    }

    public function maxTokens(): int
    {
        return $this->maxTokens;
    }

    /**
     * Answering from a handful of supplied sections needs little reasoning, so keep thinking effort low where supported.
     *
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        $provider = $provider instanceof Lab ? $provider->value : $provider;

        return match ($provider) {
            'anthropic' => ['output_config' => ['effort' => 'low']],
            default => [],
        };
    }
}
