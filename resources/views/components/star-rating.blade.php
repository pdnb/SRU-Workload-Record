@props([
    'max' => 5,
    'score' => null,
    'disabled' => false,
    'readonly' => false,
    'size' => 'md',
])

@php
    $model = $attributes->has('wire:model') || $attributes->has('wire:model.live')
        ? $attributes->wire('model')->value()
        : null;
    $interactive = $model && ! $disabled && ! $readonly;
    $iconSize = match ($size) {
        'sm' => 'size-5',
        'lg' => 'size-10',
        default => 'size-8',
    };
@endphp

<div
    {{ $attributes->except('wire:model', 'wire:model.live')->class('inline-flex items-center gap-0.5') }}
    role="radiogroup"
    aria-label="คะแนนประเมินตนเอง"
>
    @for ($star = 1; $star <= $max; $star++)
        @php
            $filled = ($score ?? 0) >= $star;
        @endphp
        <button
            type="button"
            @if ($interactive)
                wire:click="$set('{{ $model }}', {{ $filled && $score === $star ? 'null' : $star }})"
            @endif
            @disabled(! $interactive)
            @class([
                'rounded p-0.5 transition-transform',
                'cursor-pointer hover:scale-110 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent' => $interactive,
                'cursor-default' => ! $interactive,
            ])
            aria-label="{{ $star }} จาก {{ $max }} ดาว"
            aria-pressed="{{ $filled ? 'true' : 'false' }}"
        >
            <flux:icon
                icon="star"
                :variant="$filled ? 'solid' : 'outline'"
                @class([
                    $iconSize,
                    'text-amber-400' => $filled,
                    'text-zinc-300 dark:text-zinc-600' => ! $filled,
                ])
            />
        </button>
    @endfor
</div>
