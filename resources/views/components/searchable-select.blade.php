@props([
    'placeholder' => '— เลือก —',
    'searchPlaceholder' => 'ค้นหา...',
    'size' => null,
])

@php
    $buttonSize = $size === 'sm' ? 'h-8 px-2.5 text-sm' : 'h-10 px-3 text-base sm:text-sm';
@endphp

<div
    x-data="{
        open: false,
        search: '',
        options: [],
        selected: '',
        active: -1,
        panelStyle: '',
        cleanup: null,
        observer: null,

        init() {
            this.sync();
            this.$nextTick(() => this.sync());

            this.observer = new MutationObserver(() => this.sync());
            this.observer.observe(this.$refs.select, { childList: true, subtree: true, attributes: true, characterData: true });

            if (window.Livewire?.hook) {
                this.cleanup = window.Livewire.hook('commit', ({ succeed }) => {
                    succeed(() => this.$nextTick(() => this.sync()));
                });
            }
        },

        destroy() {
            this.observer?.disconnect();
            if (typeof this.cleanup === 'function') this.cleanup();
        },

        sync() {
            const select = this.$refs.select;
            this.options = Array.from(select.options).map((o) => ({
                value: o.value,
                label: o.textContent.replace(/\s+/g, ' ').trim(),
                disabled: o.disabled,
            }));
            this.selected = select.value;
        },

        get filtered() {
            const q = this.search.trim().toLowerCase();
            if (q === '') return this.options;
            return this.options.filter((o) => o.label.toLowerCase().includes(q));
        },

        get selectedLabel() {
            const match = this.options.find((o) => o.value === this.selected);
            return match ? match.label : '';
        },

        toggle() {
            this.open ? this.close() : this.show();
        },

        show() {
            this.sync();
            this.search = '';
            this.active = Math.max(0, this.filtered.findIndex((o) => o.value === this.selected));
            this.position();
            this.open = true;
            this.$nextTick(() => {
                this.$refs.search.focus();
                this.scrollToActive();
            });
        },

        close(refocus = false) {
            if (! this.open) return;
            this.open = false;
            this.search = '';
            if (refocus) this.$refs.button.focus();
        },

        position() {
            const rect = this.$refs.button.getBoundingClientRect();
            const panelHeight = 320;
            const below = window.innerHeight - rect.bottom;
            const openUp = below < panelHeight && rect.top > below;
            const width = Math.max(rect.width, 220);
            const left = Math.min(rect.left, window.innerWidth - width - 8);
            const vertical = openUp
                ? `bottom: ${window.innerHeight - rect.top + 4}px;`
                : `top: ${rect.bottom + 4}px;`;
            this.panelStyle = `position: fixed; left: ${Math.max(8, left)}px; width: ${width}px; ${vertical}`;
        },

        choose(option) {
            if (! option || option.disabled) return;
            const select = this.$refs.select;
            select.value = option.value;
            this.selected = option.value;
            select.dispatchEvent(new Event('input', { bubbles: true }));
            select.dispatchEvent(new Event('change', { bubbles: true }));
            this.close(true);
        },

        move(step) {
            const list = this.filtered;
            if (list.length === 0) return;
            let i = this.active;
            for (let n = 0; n < list.length; n++) {
                i = (i + step + list.length) % list.length;
                if (! list[i].disabled) break;
            }
            this.active = i;
            this.scrollToActive();
        },

        scrollToActive() {
            this.$nextTick(() => {
                this.$refs.list?.querySelector(`[data-index='${this.active}']`)?.scrollIntoView({ block: 'nearest' });
            });
        },
    }"
    x-on:scroll.window.capture="if (open && ! $refs.panel.contains($event.target)) close()"
    x-on:resize.window="close()"
    {{ $attributes->only('class')->merge(['class' => 'relative w-full']) }}
>
    <select
        x-ref="select"
        tabindex="-1"
        class="sr-only"
        x-on:focus="$refs.button.focus()"
        x-on:change="sync()"
        {{ $attributes->except('class') }}
    >
        {{ $slot }}
    </select>

    <button
        type="button"
        x-ref="button"
        role="combobox"
        aria-haspopup="listbox"
        x-bind:aria-expanded="open"
        @if ($attributes->has('aria-label')) aria-label="{{ $attributes->get('aria-label') }}" @endif
        x-on:click="toggle()"
        x-on:keydown.down.prevent="show()"
        x-on:keydown.up.prevent="show()"
        class="{{ $buttonSize }} flex w-full items-center justify-between gap-2 rounded-lg border border-zinc-200 border-b-zinc-300/80 bg-white text-left text-zinc-700 shadow-xs focus:outline-none focus-visible:ring-2 focus-visible:ring-accent dark:border-white/10 dark:bg-white/10 dark:text-zinc-300"
    >
        <span class="truncate" x-bind:class="selectedLabel === '' && 'text-zinc-400 dark:text-zinc-400'" x-text="selectedLabel || @js($placeholder)"></span>
        <flux:icon.chevron-up-down variant="micro" class="shrink-0 text-zinc-400" />
    </button>

    <div
        x-ref="panel"
        x-show="open"
        x-cloak
        x-transition.opacity.duration.100ms
        x-on:click.outside="close()"
        x-bind:style="panelStyle"
        class="z-50 overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-lg dark:border-zinc-600 dark:bg-zinc-700"
    >
        <div class="border-b border-zinc-200 p-2 dark:border-zinc-600">
            <input
                type="text"
                x-ref="search"
                x-model="search"
                x-on:input="active = filtered.findIndex((o) => ! o.disabled)"
                x-on:keydown.down.prevent="move(1)"
                x-on:keydown.up.prevent="move(-1)"
                x-on:keydown.enter.prevent="choose(filtered[active])"
                x-on:keydown.escape.prevent.stop="close(true)"
                x-on:keydown.tab="close()"
                placeholder="{{ $searchPlaceholder }}"
                autocomplete="off"
                class="w-full rounded-md border border-zinc-200 bg-white px-2.5 py-1.5 text-sm text-zinc-700 placeholder-zinc-400 focus:outline-none focus:ring-2 focus:ring-accent dark:border-white/10 dark:bg-white/10 dark:text-zinc-200"
            />
        </div>

        <ul x-ref="list" role="listbox" class="max-h-64 overflow-y-auto p-1">
            <template x-for="(option, index) in filtered" :key="option.value + '|' + index">
                <li
                    role="option"
                    x-bind:data-index="index"
                    x-bind:aria-selected="option.value === selected"
                    x-bind:aria-disabled="option.disabled"
                    x-on:mouseenter="active = index"
                    x-on:click="choose(option)"
                    x-bind:class="{
                        'bg-zinc-100 dark:bg-zinc-600': index === active,
                        'opacity-50 cursor-not-allowed': option.disabled,
                        'cursor-pointer': ! option.disabled,
                    }"
                    class="flex items-center gap-2 rounded-md px-2 py-1.5 text-sm text-zinc-700 dark:text-zinc-200"
                >
                    <span class="flex size-4 shrink-0 items-center justify-center">
                        <flux:icon.check variant="micro" x-show="option.value === selected" class="text-zinc-700 dark:text-zinc-200" />
                    </span>
                    <span class="truncate" x-text="option.label"></span>
                </li>
            </template>

            <li x-show="filtered.length === 0" class="px-2 py-3 text-center text-sm text-zinc-500 dark:text-zinc-400">
                ไม่พบรายการ
            </li>
        </ul>
    </div>
</div>
