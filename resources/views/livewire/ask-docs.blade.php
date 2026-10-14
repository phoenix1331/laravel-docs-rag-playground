<div class="grid gap-10 lg:grid-cols-[minmax(0,1fr)_22rem]">
    <section class="min-w-0">
        <h1 class="text-2xl font-semibold tracking-tight sm:text-3xl">Ask the Laravel {{ $version }} docs</h1>
        <p class="mt-2 max-w-prose text-muted">Answers come only from the official documentation, and link to the sections they used.</p>

        <form wire:submit="ask" class="mt-8">
            <label for="question" class="sr-only">Your question</label>

            <div class="flex flex-col gap-3 sm:flex-row">
                <div class="flex flex-1 overflow-hidden rounded-md border border-rule bg-surface focus-within:border-cite">
                    <label for="version" class="sr-only">Docs version</label>
                    <select id="version" wire:model.live="version" class="w-24 shrink-0 border-r border-rule bg-transparent pl-3 pr-1 text-sm text-muted focus:outline-none">
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

                @if ($status === 'not_covered' && ($meta['provider'] ?? null))
                    <p class="mt-6 text-xs text-muted">Sections were retrieved, but {{ $meta['provider'] }} {{ $meta['model'] }} judged that none of them answer the question.</p>
                @elseif ($meta['provider'] ?? null)
                    <p class="mt-6 text-xs text-muted">
                        Answered by {{ $meta['provider'] }} {{ $meta['model'] }}, {{ number_format($meta['input_tokens']) }} tokens in and {{ number_format($meta['output_tokens']) }} out.
                    </p>
                @elseif ($status === 'not_covered')
                    <p class="mt-6 text-xs text-muted">No section was similar enough, so no model was called.</p>
                @endif
            </article>
        @endif
    </section>

    <aside class="min-w-0 lg:pt-1" aria-labelledby="context-heading">
        <details open class="group lg:sticky lg:top-6">
            <summary class="flex cursor-pointer list-none items-baseline justify-between gap-3 border-b border-rule pb-2">
                <h2 id="context-heading" class="text-sm font-semibold">
                    Retrieved context
                    @if ($context)
                        <span class="font-normal text-muted">({{ count($context) }} sections, {{ $meta['mode'] ?? 'vector' }} search)</span>
                    @endif
                </h2>
                <span class="text-xs text-muted group-open:hidden">Show</span>
                <span class="hidden text-xs text-muted group-open:inline">Hide</span>
            </summary>

            @if ($context)
                <x-relevance-ruler :context="$context" :threshold="$this->threshold" class="mt-5" />

                <ol class="mt-5 space-y-4">
                    @foreach ($context as $section)
                        <li id="context-{{ $section['rank'] }}" class="text-sm">
                            <div class="flex items-baseline gap-2">
                                <span @class([
                                    'font-mono text-xs tabular-nums',
                                    'text-cite' => $section['cited'],
                                    'text-muted' => ! $section['cited'],
                                ])>{{ $section['rank'] }}</span>
                                <a href="{{ $section['url'] }}" target="_blank" rel="noopener" class="min-w-0 flex-1 hover:underline">{{ $section['heading_path'] }}</a>
                            </div>

                            <p class="mt-1 pl-5 font-mono text-xs text-muted">
                                {{ number_format($section['similarity'], 3) }}
                                @if ($section['similarity'] < $this->threshold)
                                    <span class="text-threshold">below threshold</span>
                                @endif
                                @if ($section['cited'])
                                    <span class="text-cite">cited</span>
                                @endif
                                <span>{{ $section['tokens'] }} tokens</span>
                            </p>

                            <p class="mt-1.5 pl-5 text-xs leading-relaxed text-muted">{{ $section['excerpt'] }}</p>
                        </li>
                    @endforeach
                </ol>
            @else
                <p class="mt-4 text-sm text-muted">After you ask, every section retrieved for the question appears here with its similarity score, so you can see why the answer cited what it did, or why it refused.</p>
            @endif
        </details>
    </aside>
</div>
