@props([
    'model',
    'readonly' => false,
    'placeholder' => '',
])

@php
    $tools = [
        ['command' => 'toggleBold', 'active' => 'bold', 'icon' => 'bold', 'label' => 'ตัวหนา'],
        ['command' => 'toggleItalic', 'active' => 'italic', 'icon' => 'italic', 'label' => 'ตัวเอียง'],
        ['command' => 'toggleUnderline', 'active' => 'underline', 'icon' => 'underline', 'label' => 'ขีดเส้นใต้'],
        ['command' => 'toggleBulletList', 'active' => 'bulletList', 'icon' => 'list-bullet', 'label' => 'รายการแบบจุด'],
        ['command' => 'toggleOrderedList', 'active' => 'orderedList', 'icon' => 'numbered-list', 'label' => 'รายการแบบตัวเลข'],
    ];
@endphp

<div
    wire:key="rich-editor-{{ $model }}-{{ $readonly ? 'readonly' : 'editable' }}"
    x-data="richEditor({
        state: $wire.entangle(@js($model)),
        readonly: @js((bool) $readonly),
        placeholder: @js($placeholder),
    })"
    {{ $attributes->class([
        'rich-editor w-full overflow-hidden rounded-lg border border-zinc-200 border-b-zinc-300/80 shadow-xs dark:border-white/10',
        'bg-white dark:bg-white/10' => ! $readonly,
        'bg-zinc-50 shadow-none dark:bg-white/[7%]' => $readonly,
    ]) }}
>
    @unless ($readonly)
        <div class="flex flex-wrap items-center gap-0.5 border-b border-zinc-200 px-1.5 py-1 dark:border-white/10">
            @foreach ($tools as $tool)
                <flux:button
                    type="button"
                    size="xs"
                    variant="subtle"
                    square
                    icon="{{ $tool['icon'] }}"
                    aria-label="{{ $tool['label'] }}"
                    title="{{ $tool['label'] }}"
                    x-on:click="run('{{ $tool['command'] }}')"
                    x-bind:class="isActive('{{ $tool['active'] }}') && '!bg-zinc-200 !text-zinc-900 dark:!bg-white/20 dark:!text-white'"
                />
            @endforeach

            <flux:button
                type="button"
                size="xs"
                variant="subtle"
                square
                icon="link"
                aria-label="ลิงก์"
                title="ลิงก์"
                x-on:click="setLink()"
                x-bind:class="isActive('link') && '!bg-zinc-200 !text-zinc-900 dark:!bg-white/20 dark:!text-white'"
            />

            <div class="mx-1 h-4 w-px bg-zinc-200 dark:bg-white/10"></div>

            <flux:button type="button" size="xs" variant="subtle" square icon="arrow-uturn-left" aria-label="เลิกทำ" title="เลิกทำ" x-on:click="run('undo')" />
            <flux:button type="button" size="xs" variant="subtle" square icon="arrow-uturn-right" aria-label="ทำซ้ำ" title="ทำซ้ำ" x-on:click="run('redo')" />
        </div>
    @endunless

    <div wire:ignore x-ref="editor"></div>
</div>
