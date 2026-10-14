@props(['context', 'threshold'])

@php
    // Stack marks that would overlap into separate lanes, closest score first.
    $lanes = [];
    $laneOf = [];
    foreach (collect($context)->sortBy('similarity') as $section) {
        $lane = 0;
        while (isset($lanes[$lane]) && $section['similarity'] - $lanes[$lane] < 0.035) {
            $lane++;
        }
        $lanes[$lane] = $section['similarity'];
        $laneOf[$section['rank']] = $lane;
    }
    $laneCount = max(1, count($lanes));
    $height = 2.25 + $laneCount * 1.5;
@endphp

{{-- Each retrieved section as a mark on a 0 to 1 similarity scale, with the refusal threshold drawn across it. --}}
<figure {{ $attributes }}>
    <div class="relative" style="height: {{ $height }}rem" role="img" aria-label="Similarity of each retrieved section, from 0 to 1, with the refusal threshold at {{ number_format($threshold, 2) }}">
        <div class="absolute inset-x-0 h-px bg-rule" style="top: {{ $height - 0.75 }}rem"></div>

        <div class="absolute top-1 bottom-1 w-px bg-threshold" style="left: {{ $threshold * 100 }}%"></div>
        <span class="absolute top-0 -translate-x-full pr-1.5 text-[11px] leading-none text-threshold" style="left: {{ $threshold * 100 }}%">refuse below</span>

        @foreach ($context as $section)
            <a
                href="#context-{{ $section['rank'] }}"
                title="{{ $section['rank'] }}. {{ $section['heading_path'] }} ({{ number_format($section['similarity'], 3) }})"
                @class([
                    'absolute flex size-5 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full border font-mono text-[10px] leading-none',
                    'border-cite bg-cite text-surface' => $section['cited'],
                    'border-muted/50 bg-surface text-muted' => ! $section['cited'],
                ])
                style="left: {{ max(0, min(1, $section['similarity'])) * 100 }}%; top: {{ $height - 0.75 - $laneOf[$section['rank']] * 1.5 }}rem"
            >{{ $section['rank'] }}</a>
        @endforeach
    </div>

    <div class="flex justify-between font-mono text-[11px] text-muted">
        <span>0</span>
        <span>0.5</span>
        <span>1</span>
    </div>
</figure>
