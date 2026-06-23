@props([
    'filename',
    'previewUrl' => null,
    'path' => null,
    'removable' => false,
    'removeWireClick' => null,
    'downloadUrl' => null,
    'muted' => false,
])

@php
    $extension = strtolower(pathinfo($path ?: $filename, PATHINFO_EXTENSION));
    $isImage = in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true);
    $isPdf = $extension === 'pdf';
@endphp

<li @class([
    'rounded-lg border border-zinc-200 px-3 py-2 text-sm dark:border-zinc-700',
    'bg-zinc-50 dark:bg-zinc-800/50' => $muted,
])>
    <div class="flex items-center justify-between gap-2">
        <span class="truncate text-zinc-600 dark:text-zinc-400">{{ $filename }}</span>
        <div class="flex shrink-0 items-center gap-1">
            @if ($downloadUrl)
                <flux:button
                    :href="$downloadUrl"
                    variant="ghost"
                    size="sm"
                    icon="arrow-down-tray"
                >
                    ดาวน์โหลด
                </flux:button>
            @endif
            @if ($removable && $removeWireClick)
                <flux:button
                    type="button"
                    wire:click="{{ $removeWireClick }}"
                    variant="ghost"
                    size="xs"
                    icon="x-mark"
                />
            @endif
        </div>
    </div>

    @if ($previewUrl && $isImage)
        <div class="relative mt-2">
            <img
                src="{{ $previewUrl }}"
                alt=""
                class="max-h-[120px] w-full rounded border border-zinc-200 object-contain dark:border-zinc-600"
                loading="lazy"
            />
            <a
                href="{{ $previewUrl }}"
                target="_blank"
                rel="noopener noreferrer"
                class="absolute inset-0"
                aria-label="ดูตัวอย่าง"
            ></a>
        </div>
    @elseif ($previewUrl && $isPdf)
        <div class="relative mt-2 h-[120px] overflow-hidden rounded border border-zinc-200 dark:border-zinc-600">
            <iframe
                src="{{ $previewUrl }}#toolbar=0"
                class="pointer-events-none h-full w-full"
                title="{{ $filename }}"
            ></iframe>
            <a
                href="{{ $previewUrl }}"
                target="_blank"
                rel="noopener noreferrer"
                class="absolute inset-0"
                aria-label="ดูตัวอย่าง"
            ></a>
        </div>
    @else
        <div class="mt-2 flex justify-center py-3">
            @if (in_array($extension, ['doc', 'docx'], true))
                <flux:icon.document-text class="size-10 text-zinc-400 dark:text-zinc-500" />
            @elseif (in_array($extension, ['xls', 'xlsx'], true))
                <flux:icon.table-cells class="size-10 text-zinc-400 dark:text-zinc-500" />
            @else
                <flux:icon.document class="size-10 text-zinc-400 dark:text-zinc-500" />
            @endif
        </div>
    @endif
</li>
