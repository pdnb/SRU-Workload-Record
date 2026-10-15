<!DOCTYPE html>
<html lang="th">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />
        <title>{{ $title ?? 'ระบบบันทึกการปฏิบัติงาน' }}</title>
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=anuphan:400,500,600,700" rel="stylesheet" />
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @fluxAppearance
    </head>
    <body class="min-h-screen bg-zinc-50 text-zinc-900 antialiased dark:bg-zinc-900 dark:text-zinc-100">
        @if (session()->has('impersonator_id') && auth()->check())
            <div class="flex flex-wrap items-center justify-center gap-3 bg-amber-500 px-4 py-2 text-sm font-medium text-white">
                <span>กำลังสวมรอยเป็น {{ auth()->user()->name }} ({{ auth()->user()->role->label() }})</span>
                <form method="POST" action="{{ route('impersonate.leave') }}">
                    @csrf
                    <flux:button type="submit" size="xs" variant="filled">ออกจากการเข้าใช้งานในฐานะผู้ใช้อื่น</flux:button>
                </form>
            </div>
        @endif

        @include('layouts.app.navbar')

        <main class="w-full px-4 py-8 sm:px-6 lg:px-8">
            {{ $slot }}
        </main>

        <flux:toast />

        @fluxScripts
    </body>
</html>
