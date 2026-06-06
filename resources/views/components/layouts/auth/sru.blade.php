<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-zinc-50 font-sans antialiased dark:bg-zinc-950">
        <div class="relative grid min-h-svh lg:grid-cols-2">
            {{-- Brand panel --}}
            <div class="relative hidden overflow-hidden bg-linear-to-br from-blue-700 via-blue-800 to-emerald-900 px-10 py-12 text-white lg:flex lg:flex-col">
                <div class="pointer-events-none absolute -right-24 -top-24 size-80 rounded-full bg-white/10 blur-3xl"></div>
                <div class="pointer-events-none absolute -bottom-32 -left-16 size-96 rounded-full bg-emerald-400/20 blur-3xl"></div>

                <div class="relative z-10 flex items-center gap-3">
                    <span class="flex size-11 items-center justify-center rounded-xl bg-white/15 ring-1 ring-white/25 backdrop-blur-sm">
                        <x-app-logo-icon class="size-7 fill-current text-white" />
                    </span>
                    <div>
                        <p class="text-sm font-medium text-blue-100">มหาวิทยาลัยราชภัฏสุราษฎร์ธานี</p>
                        <p class="text-lg font-semibold leading-tight">ระบบบันทึกภาระงาน</p>
                    </div>
                </div>

                <div class="relative z-10 my-auto max-w-md space-y-6 py-16">
                    <div class="space-y-3">
                        <h1 class="text-3xl font-semibold leading-tight tracking-tight">
                            บันทึกและติดตามภาระงานได้ในที่เดียว
                        </h1>
                        <p class="text-base leading-relaxed text-blue-100/90">
                            สำหรับบุคลากร ผู้บังคับบัญชา และงานบุคคล — จัดการผลงานตามรอบประเมินอย่างเป็นระบบ
                        </p>
                    </div>

                    <ul class="space-y-3 text-sm text-blue-50/95">
                        <li class="flex items-start gap-3">
                            <flux:icon.clipboard-document-check class="mt-0.5 size-5 shrink-0 text-emerald-200" />
                            <span>บันทึกผลงานและแนบหลักฐานตามโครงสร้างภาระงาน</span>
                        </li>
                        <li class="flex items-start gap-3">
                            <flux:icon.user-group class="mt-0.5 size-5 shrink-0 text-emerald-200" />
                            <span>ผู้บังคับบัญชาตรวจสอบและให้ความเห็นได้ทันที</span>
                        </li>
                        <li class="flex items-start gap-3">
                            <flux:icon.chart-bar class="mt-0.5 size-5 shrink-0 text-emerald-200" />
                            <span>งานบุคคลสรุปภาพรวมและส่งออกรายงาน</span>
                        </li>
                    </ul>
                </div>

                <p class="relative z-10 text-xs text-blue-200/80">
                    &copy; {{ date('Y') }} มหาวิทยาลัยราชภัฏสุราษฎร์ธานี
                </p>
            </div>

            {{-- Form panel --}}
            <div class="flex flex-col justify-center px-6 py-10 sm:px-10 lg:px-16 xl:px-24">
                <div class="mb-8 flex items-center justify-between lg:absolute lg:right-6 lg:top-6 lg:mb-0 xl:right-10 xl:top-10">
                    <div class="flex items-center gap-2 lg:hidden">
                        <span class="flex size-10 items-center justify-center rounded-lg bg-blue-700 text-white">
                            <x-app-logo-icon class="size-6 fill-current" />
                        </span>
                        <div>
                            <p class="text-xs text-zinc-500 dark:text-zinc-400">SRU Workload</p>
                            <p class="text-sm font-semibold text-zinc-900 dark:text-white">ระบบบันทึกภาระงาน</p>
                        </div>
                    </div>
                    @include('partials.theme-toggle')
                </div>

                <div class="mx-auto w-full max-w-md">
                    {{ $slot }}
                </div>
            </div>
        </div>
        @fluxScripts
    </body>
</html>
