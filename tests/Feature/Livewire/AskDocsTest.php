<?php

declare(strict_types=1);

use App\Ai\Agents\DocsAgent;
use App\Answering\DocsAssistant;
use App\Livewire\AskDocs;
use App\Models\DocChunk;
use App\Retrieval\RetrievalResult;
use App\Retrieval\RetrievedChunk;
use App\Retrieval\Retriever;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Livewire\Livewire;

beforeEach(function () {
    config()->set('rag.retrieval.min_similarity', 0.5);
    config()->set('rag.chat.provider', 'gemini');
    DocChunk::factory()->create();

    $hits = [
        new RetrievedChunk(DocChunk::factory()->make(['heading_path' => 'Queues > Job Batching', 'url' => 'https://laravel.com/docs/13.x/queues#job-batching']), 1, 0.8, 0.8),
        new RetrievedChunk(DocChunk::factory()->make(['heading_path' => 'Routing > Basics']), 2, 0.4, 0.4),
    ];

    $this->retriever = Mockery::mock(Retriever::class);
    $this->retriever->allows('retrieve')->andReturnUsing(fn (string $q, string $v) => new RetrievalResult($q, $v, 'vector', $hits));
    app()->instance(Retriever::class, $this->retriever);
});

it('answers a question with citation chips and sources', function () {
    DocsAgent::fake(['Use `Bus::batch()` [1].']);

    Livewire::test(AskDocs::class)
        ->set('question', 'How do I batch jobs?')
        ->call('ask')
        ->assertSet('streaming', true)
        ->assertSet('asked', 'How do I batch jobs?')
        ->assertSeeHtml('wire:stream="answer"')
        ->call('generate')
        ->assertSet('streaming', false)
        ->assertSet('status', 'answered')
        ->assertSee('How do I batch jobs?')
        ->assertSeeHtml('<code>Bus::batch()</code>')
        ->assertSeeHtml('href="#source-1" class="cite"')
        ->assertSee('Queues > Job Batching')
        ->assertSeeHtml('https://laravel.com/docs/13.x/queues#job-batching')
        ->assertCount('sources', 1)
        ->assertSet('sources.0.heading_path', 'Queues > Job Batching');
});

it('requires a question', function () {
    Livewire::test(AskDocs::class)
        ->call('ask')
        ->assertHasErrors(['question' => 'required'])
        ->assertSee('Type a question about the Laravel docs.');
});

it('only accepts configured versions', function () {
    Livewire::test(AskDocs::class)
        ->set('question', 'How do I batch jobs?')
        ->set('version', '5.x')
        ->call('ask')
        ->assertHasErrors('version');
});

it('marks versions that have not been ingested', function () {
    Livewire::test(AskDocs::class)
        ->assertSeeInOrder(['13.x', '12.x (not ingested)', '11.x (not ingested)', '10.x (not ingested)']);
});

it('explains when the docs do not cover a question', function () {
    config()->set('rag.retrieval.min_similarity', 0.95);
    DocsAgent::fake()->preventStrayPrompts();

    Livewire::test(AskDocs::class)
        ->set('question', 'How do I deploy to Mars?')
        ->call('ask')
        ->call('generate')
        ->assertSet('status', 'not_covered')
        ->assertSee('appear to be covered in the Laravel 13.x documentation')
        ->assertSee('no model was called');
});

it('shows what to do when the provider is overloaded', function () {
    DocsAgent::fake(fn () => throw ProviderOverloadedException::forProvider('gemini'));
    app()->instance(DocsAssistant::class, app(DocsAssistant::class)->retryDelays([]));

    Livewire::test(AskDocs::class)
        ->set('question', 'How do I batch jobs?')
        ->call('ask')
        ->call('generate')
        ->assertSet('streaming', false)
        ->assertSet('status', null)
        ->assertSee('overloaded right now');
});

it('does nothing when generate is called without a pending question', function () {
    DocsAgent::fake()->preventStrayPrompts();

    Livewire::test(AskDocs::class)->call('generate')->assertSet('status', null);

    DocsAgent::assertNeverPrompted();
});

it('shows every retrieved section with its score and cited flag', function () {
    DocsAgent::fake(['Use `Bus::batch()` [1].']);

    Livewire::test(AskDocs::class)
        ->set('question', 'How do I batch jobs?')
        ->call('ask')
        ->call('generate')
        ->assertSet('context.0.cited', true)
        ->assertSet('context.1.cited', false)
        ->assertSet('context.1.similarity', 0.4)
        ->assertSee('Retrieved context')
        ->assertSee('(2 sections, vector search)')
        ->assertSee('Routing > Basics')
        ->assertSee('below threshold')
        ->assertSeeHtml('refuse below');
});

it('shows the retrieved context for refusals too', function () {
    config()->set('rag.retrieval.min_similarity', 0.95);
    DocsAgent::fake()->preventStrayPrompts();

    Livewire::test(AskDocs::class)
        ->set('question', 'How do I deploy to Mars?')
        ->call('ask')
        ->call('generate')
        ->assertCount('context', 2)
        ->assertSeeHtml('left: 95%');
});

it('says when the model judged the sections insufficient', function () {
    DocsAgent::fake(['NOT_COVERED']);

    Livewire::test(AskDocs::class)
        ->set('question', 'How do I configure Kubernetes?')
        ->call('ask')
        ->call('generate')
        ->assertSet('status', 'not_covered')
        ->assertSee('judged that none of them answer the question');
});
