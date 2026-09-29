@php
    $user = auth()->user();
@endphp

<header class="sticky top-0 z-50 w-full border-b border-zinc-200 bg-white/95 backdrop-blur dark:border-zinc-700 dark:bg-zinc-900/95">
    <div class="flex w-full items-center justify-between gap-4 px-4 py-3 sm:px-6 lg:px-8">
        <div class="flex min-w-0 flex-1 items-center gap-6">
            <a href="{{ $user->isStaff() ? route('staff.dashboard') : route('hr.dashboard') }}" class="cursor-pointer shrink-0 text-lg font-semibold text-zinc-900 dark:text-white" wire:navigate>
                ระบบบันทึกการปฏิบัติงาน
            </a>

            <flux:navbar class="hidden md:flex">
                @if ($user->isStaff())
                    <flux:navbar.item :href="route('staff.dashboard')" :current="request()->routeIs('staff.dashboard')" wire:navigate>
                        แดชบอร์ด
                    </flux:navbar.item>
                    <flux:navbar.item :href="route('staff.records.index')" :current="request()->routeIs('staff.records.*')" wire:navigate>
                        บันทึกของฉัน
                    </flux:navbar.item>
                @endif

                @if ($user->isHrOrAdmin())
                    <flux:navbar.item :href="route('hr.dashboard')" :current="request()->routeIs('hr.*')" wire:navigate>
                        แดชบอร์ด
                    </flux:navbar.item>
                @endif

                @if ($user->isAdmin())
                    <flux:navbar.item :href="route('admin.periods')" :current="request()->routeIs('admin.periods')" wire:navigate>
                        จัดการรอบประเมิน
                    </flux:navbar.item>
                    <flux:navbar.item :href="route('admin.workloads')" :current="request()->routeIs('admin.workloads')" wire:navigate>
                        โครงสร้างภาระงาน
                    </flux:navbar.item>
                    <flux:navbar.item :href="route('admin.users')" :current="request()->routeIs('admin.users')" wire:navigate>
                        ผู้ใช้และตำแหน่ง
                    </flux:navbar.item>
                @endif
            </flux:navbar>
        </div>

        <div class="flex shrink-0 items-center gap-3">
            @include('partials.theme-toggle')

            <div class="hidden text-right sm:block">
                <div class="text-sm font-medium">{{ $user->name }}</div>
                <flux:badge size="sm" color="zinc">{{ $user->role->label() }}</flux:badge>
            </div>

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <flux:button type="submit" variant="ghost" size="sm" icon="arrow-right-start-on-rectangle">
                    ออกจากระบบ
                </flux:button>
            </form>
        </div>
    </div>

    <nav class="flex w-full gap-1 overflow-x-auto border-t border-zinc-100 px-4 py-2 sm:px-6 md:hidden lg:px-8 dark:border-zinc-800">
        @if ($user->isStaff())
            <flux:button :href="route('staff.dashboard')" variant="ghost" size="sm" wire:navigate>แดชบอร์ด</flux:button>
            <flux:button :href="route('staff.records.index')" variant="ghost" size="sm" wire:navigate>บันทึกของฉัน</flux:button>
        @endif
        @if ($user->isHrOrAdmin())
            <flux:button :href="route('hr.dashboard')" variant="ghost" size="sm" wire:navigate>แดชบอร์ด</flux:button>
        @endif
        @if ($user->isAdmin())
            <flux:button :href="route('admin.periods')" variant="ghost" size="sm" wire:navigate>รอบประเมิน</flux:button>
            <flux:button :href="route('admin.workloads')" variant="ghost" size="sm" wire:navigate>ภาระงาน</flux:button>
            <flux:button :href="route('admin.users')" variant="ghost" size="sm" wire:navigate>ผู้ใช้</flux:button>
        @endif
    </nav>
</header>
