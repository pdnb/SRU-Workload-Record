@props([
    'readonly' => false,
    'modalEntry' => [
        'id' => null,
        'detail' => '',
        'result' => '',
        'hours' => '1',
        'evidence_type' => 'url',
        'evidence' => '',
    ],
    'evidenceUploads' => [],
])

@php
    $evidenceFiles = ($modalEntry['evidence_type'] ?? 'url') === 'file'
        ? (is_array($modalEntry['evidence'] ?? null)
            ? array_values(array_filter($modalEntry['evidence'], fn ($path) => filled($path)))
            : (filled($modalEntry['evidence'] ?? null) ? [$modalEntry['evidence']] : []))
        : [];
@endphp

<div class="space-y-4">
    <flux:field>
        <flux:label>รายละเอียดการดำเนินงาน <span class="text-red-500">*</span></flux:label>
        <flux:textarea
            wire:model="modalEntry.detail"
            rows="4"
            placeholder="อธิบายรายละเอียดการดำเนินงานอย่างน้อย 10 ตัวอักษร"
            :disabled="$readonly"
        />
        <flux:error name="modalEntry.detail" />
    </flux:field>

    <flux:field>
        <flux:label>งาน</flux:label>
        <flux:input
            wire:model="modalEntry.hours"
            type="number"
            step="1"
            min="1"
            placeholder="1"
            :disabled="$readonly"
        />
        <flux:error name="modalEntry.hours" />
    </flux:field>

    <div>
        <flux:label class="mb-2">หลักฐานประกอบ</flux:label>
        <p class="mb-3 text-sm text-zinc-500">อัปโหลดไฟล์ได้หลายไฟล์ หรือ วาง URL</p>

        @unless ($readonly)
            <div class="mb-4 flex gap-2">
                <flux:button
                    type="button"
                    size="sm"
                    wire:click="setEvidenceType('url')"
                    variant="{{ ($modalEntry['evidence_type'] ?? 'url') === 'url' ? 'primary' : 'ghost' }}"
                >
                    ลิงก์
                </flux:button>
                <flux:button
                    type="button"
                    size="sm"
                    wire:click="setEvidenceType('file')"
                    variant="{{ ($modalEntry['evidence_type'] ?? 'url') === 'file' ? 'primary' : 'ghost' }}"
                >
                    ไฟล์
                </flux:button>
            </div>
        @endunless

        @if ($readonly)
            @if (($modalEntry['evidence_type'] ?? 'url') === 'file' && $evidenceFiles !== [])
                <ul class="space-y-2">
                    @foreach ($evidenceFiles as $index => $path)
                        <li>
                            @if (filled($modalEntry['id'] ?? null))
                                <flux:button
                                    :href="route('staff.evidence.download', ['entry' => $modalEntry['id'], 'fileIndex' => $index])"
                                    variant="ghost"
                                    size="sm"
                                    icon="arrow-down-tray"
                                >
                                    ดาวน์โหลด {{ basename($path) }}
                                </flux:button>
                            @else
                                <span class="text-sm text-zinc-600 dark:text-zinc-400">{{ basename($path) }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @elseif (filled($modalEntry['evidence'] ?? null))
                <flux:link :href="$modalEntry['evidence']" target="_blank">
                    เปิดลิงก์หลักฐาน
                </flux:link>
            @else
                <span class="text-sm text-zinc-500">ไม่มีหลักฐาน</span>
            @endif
        @elseif (($modalEntry['evidence_type'] ?? 'url') === 'url')
            <div wire:key="evidence-url">
            <flux:input
                wire:model="modalEntry.evidence"
                type="url"
                placeholder="https://..."
            />
            <flux:error name="modalEntry.evidence" />
            </div>
        @else
            <div wire:key="evidence-file">
            <input
                type="file"
                wire:model="evidenceUploads"
                multiple
                accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.webp"
                class="block w-full text-sm text-zinc-600 file:mr-4 file:rounded-lg file:border-0 file:bg-zinc-100 file:px-4 file:py-2 file:text-sm file:font-medium dark:text-zinc-400 dark:file:bg-zinc-800"
            />
            <flux:error name="evidenceUploads" />
            <flux:error name="evidenceUploads.*" />

            @if ($evidenceUploads !== [])
                <ul class="mt-3 space-y-2">
                    @foreach ($evidenceUploads as $index => $upload)
                        <li class="flex items-center justify-between gap-2 rounded-lg border border-zinc-200 px-3 py-2 text-sm dark:border-zinc-700">
                            <span class="truncate text-zinc-600 dark:text-zinc-400">{{ $upload->getClientOriginalName() }}</span>
                            <flux:button
                                type="button"
                                wire:click="removeEvidenceUpload({{ $index }})"
                                variant="ghost"
                                size="xs"
                                icon="x-mark"
                            />
                        </li>
                    @endforeach
                </ul>
            @endif

            @if ($evidenceFiles !== [])
                <ul class="mt-3 space-y-2">
                    @foreach ($evidenceFiles as $index => $path)
                        <li class="flex items-center justify-between gap-2 rounded-lg border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800/50">
                            <span class="truncate text-zinc-600 dark:text-zinc-400">{{ basename($path) }}</span>
                            @unless ($readonly)
                                <flux:button
                                    type="button"
                                    wire:click="removeStoredEvidenceFile({{ $index }})"
                                    variant="ghost"
                                    size="xs"
                                    icon="x-mark"
                                />
                            @endunless
                        </li>
                    @endforeach
                </ul>
            @endif

            <div wire:loading wire:target="evidenceUploads" class="mt-2 text-sm text-zinc-500">กำลังอัปโหลด...</div>
            <p class="mt-2 text-xs text-zinc-400">เลือกได้หลายไฟล์ — รองรับ PDF, Word, Excel, JPG, PNG, WebP ขนาดไม่เกิน 10 MB ต่อไฟล์</p>
            </div>
        @endif
    </div>
</div>
