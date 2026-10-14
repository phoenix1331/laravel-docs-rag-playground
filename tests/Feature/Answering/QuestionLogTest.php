<?php

declare(strict_types=1);

use App\Ai\Agents\DocsAgent;
use App\Answering\DocsAssistant;
use App\Models\DocChunk;
use App\Models\Question;
use App\Retrieval\RetrievalResult;
use App\Retrieval\RetrievedChunk;
use App\Retrieval\Retriever;

beforeEach(function () {
    config()->set('rag.retrieval.min_similarity', 0.5);
    config()->set('rag.chat.provider', 'gemini');

    $this->chunks = DocChunk::factory()->count(2)->create();
    $hits = [
        new RetrievedChunk($this->chunks[0], 1, 0.8, 0.8),
        new RetrievedChunk($this->chunks[1], 2, 0.6, 0.6),
    ];

    $retriever = Mockery::mock(Retriever::class);
    $retriever->allows('retrieve')->andReturnUsing(fn (string $q, string $v) => new RetrievalResult($q, $v, 'vector', $hits));
    app()->instance(Retriever::class, $retriever);
});

it('logs an answered question with what was retrieved and cited', function () {
    DocsAgent::fake(['Use batches [2].']);

    app(DocsAssistant::class)->answer('How do I batch jobs?', '13.x');

    $question = Question::sole();

    expect($question->status)->toBe('answered')
        ->and($question->question)->toBe('How do I batch jobs?')
        ->and($question->channel)->toBe('cli')
        ->and($question->mode)->toBe('vector')
        ->and($question->answer)->toBe('Use batches [2].')
        ->and($question->cited_chunk_ids)->toBe([$this->chunks[1]->id])
        ->and($question->best_similarity)->toBe(0.8)
        ->and($question->retrieved)->toBe([
            ['chunk_id' => $this->chunks[0]->id, 'rank' => 1, 'similarity' => 0.8, 'cited' => false],
            ['chunk_id' => $this->chunks[1]->id, 'rank' => 2, 'similarity' => 0.6, 'cited' => true],
        ])
        ->and($question->provider)->toBe('gemini')
        ->and($question->duration_ms)->toBeGreaterThanOrEqual(0);
});

it('logs refusals made without a model', function () {
    config()->set('rag.retrieval.min_similarity', 0.95);
    DocsAgent::fake()->preventStrayPrompts();

    app(DocsAssistant::class)->answer('Mars?', '13.x');

    expect(Question::sole())
        ->status->toBe('not_covered')
        ->provider->toBeNull()
        ->input_tokens->toBe(0);
});

it('logs failures and still throws them', function () {
    DocsAgent::fake(fn () => throw new RuntimeException('Invalid API key'));

    expect(fn () => app(DocsAssistant::class)->answer('How do I batch jobs?', '13.x'))
        ->toThrow(RuntimeException::class);

    expect(Question::sole())
        ->status->toBe('error')
        ->error->toContain('Invalid API key')
        ->and(Question::sole()->retrieved)->toHaveCount(2);
});

it('can be turned off', function () {
    config()->set('rag.log_questions', false);
    DocsAgent::fake(['Use batches [1].']);

    app(DocsAssistant::class)->answer('How do I batch jobs?', '13.x');

    expect(Question::count())->toBe(0);
});
