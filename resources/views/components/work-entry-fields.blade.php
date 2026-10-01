@props([
    'readonly' => false,
    'modalEntry' => [
        'id' => null,
        'detail' => '',
        'result' => '',
        'hours' => '1',
        'evidence_type' => 'url',
        'evidence' => '',
        'evidence_files' => [],
    ],
    'evidenceUploads' => [],
])

@php
    use App\Models\WorkEntryEvidence;
    use Illuminate\Support\Facades\Storage;

    $evidenceFiles = ($modalEntry['evidence_type'] ?? 'url') === 'file'
        ? array_values(array_filter(
            $modalEntry['evidence_files'] ?? [],
            fn ($file) => is_array($file) && filled($file['path'] ?? null),
        ))
        : [];
@endphp

<div class="space-y-4">
    <flux:field>
        <flux:label>รายละเอียดการดำเนินงาน <span class="text-red-500">*</span></flux:label>
        <flux:textarea
            wire:model="modalEntry.detail"
            rows="6"
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

    @unless ($modalEntry['is_mirror'] ?? false)
        <div>
            <flux:label class="mb-2">ผู้ร่วมงาน</flux:label>
            <p class="mb-3 text-sm text-zinc-500">เลือกบุคลากรที่ร่วมทำงาน — ผลงานจะถูกบันทึกในบันทึกของผู้ร่วมงานด้วย</p>

            @unless ($readonly)
                <flux:input
                    wire:model.live.debounce.300ms="collaboratorSearch"
                    type="search"
                    placeholder="ค้นหาชื่อหรืออีเมล (อย่างน้อย 2 ตัวอักษร)"
                    class="mb-3"
                />

                @if ($this->collaboratorSearchResults->isNotEmpty())
                    <ul class="mb-3 max-h-40 overflow-y-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
                        @foreach ($this->collaboratorSearchResults as $user)
                            <li wire:key="collab-search-{{ $user->id }}">
                                <button
                                    type="button"
                                    wire:click="addCollaborator({{ $user->id }})"
                                    class="flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-sm hover:bg-zinc-50 dark:hover:bg-zinc-800"
                                >
                                    <span>{{ $user->name }}</span>
                                    @if (filled($user->department))
                                        <span class="text-xs text-zinc-500">{{ $user->department }}</span>
                                    @endif
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @elseif ($this->frequentCollaborators->isNotEmpty())
                    <div class="mb-3 flex flex-wrap items-center gap-2">
                        <span class="text-xs text-zinc-500">ใช้บ่อย:</span>
                        @foreach ($this->frequentCollaborators as $user)
                            <button
                                type="button"
                                wire:key="collab-frequent-{{ $user->id }}"
                                wire:click="addCollaborator({{ $user->id }})"
                                class="rounded-full border border-zinc-200 px-2.5 py-0.5 text-xs text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800"
                            >
                                + {{ $user->name }}
                            </button>
                        @endforeach
                    </div>
                @endif
            @endunless

            @if ($this->modalCollaborators->isNotEmpty())
                <div class="flex flex-wrap gap-2">
                    @foreach ($this->modalCollaborators as $user)
                        <flux:badge size="sm" color="zinc" class="inline-flex items-center gap-1">
                            {{ $user->name }}
                            @unless ($readonly)
                                <button
                                    type="button"
                                    wire:click="removeCollaborator({{ $user->id }})"
                                    class="ml-1 text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200"
                                    aria-label="ลบ {{ $user->name }}"
                                >
                                    &times;
                                </button>
                            @endunless
                        </flux:badge>
                    @endforeach
                </div>
            @elseif ($readonly)
                <span class="text-sm text-zinc-500">ไม่มีผู้ร่วมงาน</span>
            @endif

            <flux:error name="modalEntry.collaborator_ids" />
            <flux:error name="modalEntry.collaborator_ids.*" />
        </div>
    @endunless

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
                    @foreach ($evidenceFiles as $file)
                        @php
                            $evidenceId = $file['id'] ?? null;
                            $filePath = $file['path'] ?? '';
                            $fileName = $file['filename'] ?? basename($filePath);
                            $previewUrl = filled($evidenceId) && WorkEntryEvidence::isPathPreviewable($filePath)
                                ? route('staff.evidence.preview', ['evidence' => $evidenceId])
                                : null;
                        @endphp
                        <x-work-entry-evidence-item
                            :filename="$fileName"
                            :path="$filePath"
                            :preview-url="$previewUrl"
                            :download-url="filled($evidenceId) ? route('staff.evidence.download', ['evidence' => $evidenceId]) : null"
                        />
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
            <div
                x-data="evidenceCapture"
                @paste.window="onPaste($event)"
                class="relative"
            >
                <input
                    x-ref="fileInput"
                    type="file"
                    wire:model="evidenceUploads"
                    multiple
                    accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.webp"
                    class="sr-only"
                />
                <div
                    role="button"
                    tabindex="0"
                    class="flex cursor-pointer flex-col items-center justify-center rounded-lg border-2 border-dashed px-4 py-8 text-center transition-colors"
                    :class="dragging
                        ? 'border-zinc-500 bg-zinc-50 dark:border-zinc-400 dark:bg-zinc-800/50'
                        : 'border-zinc-300 bg-white hover:border-zinc-400 hover:bg-zinc-50 dark:border-zinc-600 dark:bg-white/5 dark:hover:border-zinc-500 dark:hover:bg-zinc-800/30'"
                    @click="openFilePicker()"
                    @keydown.enter.prevent="openFilePicker()"
                    @keydown.space.prevent="openFilePicker()"
                    @dragover.prevent="dragging = true"
                    @dragleave.prevent="dragging = false"
                    @drop.prevent="handleDrop($event)"
                >
                    <flux:icon.cloud-arrow-up class="mb-2 size-8 text-zinc-400 dark:text-zinc-500" />
                    <p class="text-sm text-zinc-600 dark:text-zinc-400">ลากไฟล์มาวางหรือคลิกเพื่อเลือกไฟล์</p>
                    <p class="mt-1 text-xs text-zinc-400 dark:text-zinc-500">หรือกด Ctrl+V เพื่อวางภาพจาก Snipping Tool (Win+Shift+S)</p>
                </div>

                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <flux:button
                        type="button"
                        size="sm"
                        variant="ghost"
                        icon="camera"
                        x-show="canCapture"
                        x-bind:disabled="capturing"
                        @click="startCapture()"
                    >
                        จับภาพหน้าจอ
                    </flux:button>
                    <flux:button
                        type="button"
                        size="sm"
                        variant="ghost"
                        icon="clipboard-document"
                        @click="pasteFromClipboard()"
                    >
                        วางภาพจากคลิปบอร์ด
                    </flux:button>
                </div>
                <p x-show="hint" x-text="hint" x-cloak class="mt-1 text-xs text-amber-600 dark:text-amber-400"></p>

                <dialog
                    x-ref="cropDialog"
                    @cancel.prevent="cancelCrop()"
                    class="m-auto max-h-[95vh] max-w-[95vw] rounded-xl bg-white p-4 shadow-xl backdrop:bg-black/60 dark:bg-zinc-900"
                >
                    <template x-if="cropping">
                        <div class="flex flex-col gap-3">
                            <div>
                                <p class="text-sm font-medium text-zinc-800 dark:text-zinc-100">เลือกพื้นที่ที่ต้องการแนบ</p>
                                <p class="text-xs text-zinc-500 dark:text-zinc-400">ลากเมาส์เพื่อครอปภาพ หากไม่เลือกจะแนบทั้งภาพ</p>
                            </div>
                            <div
                                x-ref="cropArea"
                                class="relative inline-block cursor-crosshair overflow-hidden touch-none select-none self-center"
                                @pointerdown="beginSelect($event)"
                                @pointermove="moveSelect($event)"
                                @pointerup="endSelect()"
                                @pointercancel="endSelect()"
                            >
                                <img :src="snapshotUrl" alt="ภาพหน้าจอที่จับได้" draggable="false" class="block max-h-[70vh] max-w-full" />
                                <div
                                    class="pointer-events-none absolute border-2 border-sky-500 bg-sky-500/10 shadow-[0_0_0_9999px_rgba(0,0,0,0.45)]"
                                    :style="selectionStyle()"
                                ></div>
                            </div>
                            <div class="flex justify-end gap-2">
                                <flux:button type="button" size="sm" variant="ghost" @click="cancelCrop()">ยกเลิก</flux:button>
                                <flux:button type="button" size="sm" variant="primary" icon="check" @click="confirmCrop()">ใช้ภาพนี้</flux:button>
                            </div>
                        </div>
                    </template>
                </dialog>
            </div>
            <flux:error name="evidenceUploads" />
            <flux:error name="evidenceUploads.*" />

            @if ($evidenceUploads !== [])
                <ul class="mt-3 space-y-2">
                    @foreach ($evidenceUploads as $index => $upload)
                        @php
                            $uploadName = $upload->getClientOriginalName();
                            $uploadPreviewable = method_exists($upload, 'isPreviewable') && $upload->isPreviewable();
                            $uploadPreviewUrl = $uploadPreviewable ? $upload->temporaryUrl() : null;
                        @endphp
                        <x-work-entry-evidence-item
                            :filename="$uploadName"
                            :path="$uploadName"
                            :preview-url="$uploadPreviewUrl"
                            removable
                            :remove-wire-click="'removeEvidenceUpload('.$index.')'"
                        />
                    @endforeach
                </ul>
            @endif

            @if ($evidenceFiles !== [])
                <ul class="mt-3 space-y-2">
                    @foreach ($evidenceFiles as $index => $file)
                        @php
                            $evidenceId = $file['id'] ?? null;
                            $filePath = $file['path'] ?? '';
                            $fileName = $file['filename'] ?? basename($filePath);
                            $previewUrl = match (true) {
                                ! WorkEntryEvidence::isPathPreviewable($filePath) => null,
                                filled($evidenceId) => route('staff.evidence.preview', ['evidence' => $evidenceId]),
                                default => Storage::disk('public')->url($filePath),
                            };
                        @endphp
                        <x-work-entry-evidence-item
                            :filename="$fileName"
                            :path="$filePath"
                            :preview-url="$previewUrl"
                            removable
                            :remove-wire-click="'removeStoredEvidenceFile('.$index.')'"
                            muted
                        />
                    @endforeach
                </ul>
            @endif

            <div wire:loading wire:target="evidenceUploads" class="mt-2 text-sm text-zinc-500">กำลังอัปโหลด...</div>
            <p class="mt-2 text-xs text-zinc-400">รองรับ PDF, Word, Excel, JPG, PNG, WebP ขนาดไม่เกิน 10 MB ต่อไฟล์</p>
            </div>
        @endif
    </div>
</div>
