<div class="grid gap-10 lg:grid-cols-[minmax(0,1fr)_22rem]">
    <section class="min-w-0">
        <h1 class="text-2xl font-semibold tracking-tight sm:text-3xl">Ask the Laravel {{ $version }} docs</h1>
        <p class="mt-2 max-w-prose text-muted">Answers come only from the official documentation, and link to the sections they used.</p>

        <form wire:submit="ask" class="mt-8">
            <label for="question" class="sr-only">Your question</label>

            <div class="flex flex-col gap-3 sm:flex-row">
                <div class="flex flex-1 overflow-hidden rounded-md border border-rule bg-surface focus-within:border-cite">
                    <label for="version" class="sr-only">Docs version</label>
                    <select id="version" wire:model.live="version" class="w-auto shrink-0 border-r border-rule bg-transparent pl-3 pr-1 text-sm text-muted focus:outline-none">
                        @foreach ($this->versions as $name => $chunks)
                            <option value="{{ $name }}" @disabled($chunks === 0)>{{ $name }}{{ $chunks === 0 ? ' (not ingested)' : '' }}</option>
                        @endforeach
                    </select>

                    <input
                        id="question"
                        type="text"
                        wire:model="question"
                        placeholder="How do I run code after a batch of jobs finishes?"
                        autocomplete="off"
                        autofocus
                        class="min-w-0 flex-1 bg-transparent px-3 py-3 placeholder:text-muted/70 focus:outline-none"
                    >
                </div>

                <button
                    type="submit"
                    class="rounded-md bg-ink px-5 py-3 text-sm font-semibold text-paper hover:opacity-90 disabled:opacity-50"
                    wire:loading.attr="disabled"
                    wire:target="ask, generate"
                >
                    <span wire:loading.remove wire:target="ask, generate">Ask</span>
                    <span wire:loading wire:target="ask, generate">Answering</span>
                </button>
            </div>

            @error('question')
                <p class="mt-2 text-sm text-danger">{{ $message }}</p>
            @enderror
        </form>

        @if ($error)
            <p class="mt-8 max-w-prose rounded-md border border-danger/40 px-4 py-3 text-sm text-danger" role="alert">{{ $error }}</p>
        @endif

        @if ($asked)
            <article class="mt-10" aria-live="polite">
                <p class="text-sm text-muted">{{ $asked }}</p>

                @if ($streaming)
                    {{-- Raw text while it streams; replaced by rendered Markdown once complete. --}}
                    <div class="answer mt-3 whitespace-pre-wrap" wire:stream="answer"></div>
                    <p class="mt-3 text-sm text-muted" wire:loading wire:target="generate">Searching the docs and writing an answer.</p>
                @else
                    <div @class(['answer mt-3', 'text-muted' => $status === 'not_covered'])>
                        {!! $answerHtml !!}
                    </div>
                @endif

                @if ($sources)
                    <section class="mt-8 max-w-prose border-t border-rule pt-5" aria-labelledby="sources-heading">
                        <h2 id="sources-heading" class="text-sm font-semibold">Sources</h2>

                        <ol class="mt-3 space-y-2 text-sm">
                            @foreach ($sources as $source)
                                <li id="source-{{ $source['number'] }}" class="flex gap-3">
                                    <span class="cite shrink-0">{{ $source['number'] }}</span>
                                    <a href="{{ $source['url'] }}" target="_blank" rel="noopener" class="hover:text-cite hover:underline">{{ $source['heading_path'] }}</a>
                                </li>
                            @endforeach
                        </ol>
                    </section>
                @endif

                @if ($meta['provider'] ?? null)
                    <p class="mt-6 text-xs text-muted">
                        Answered by {{ $meta['provider'] }} {{ $meta['model'] }}, {{ number_format($meta['input_tokens']) }} tokens in and {{ number_format($meta['output_tokens']) }} out.
                    </p>
                @elseif ($status === 'not_covered')
                    <p class="mt-6 text-xs text-muted">No section was similar enough, so no model was called.</p>
                @endif
            </article>
        @endif
    </section>

    <aside class="min-w-0"></aside>
</div>
