@props([
    'icon' => null,
    'message' => null,
])

<div {{ $attributes->class('flex flex-col items-center justify-center rounded-xl border border-dashed border-zinc-300 p-12 text-center dark:border-zinc-600') }}>
    @if ($icon)
        <div class="mb-4 flex items-center justify-center text-zinc-400 dark:text-zinc-500">
            <flux:icon :icon="$icon" class="size-12" />
        </div>
    @elseif (isset($iconSlot))
        <div class="mb-4 flex items-center justify-center">{{ $iconSlot }}</div>
    @endif

    @if ($message)
        <p class="max-w-md text-zinc-600 dark:text-zinc-400">{{ $message }}</p>
    @elseif (isset($messageSlot))
        <div class="max-w-md text-zinc-600 dark:text-zinc-400">{{ $messageSlot }}</div>
    @endif

    @if ($slot->isNotEmpty())
        <div class="mt-6">{{ $slot }}</div>
    @endif
</div>
