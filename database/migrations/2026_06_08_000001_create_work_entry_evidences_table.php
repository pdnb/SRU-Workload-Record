<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_entry_evidences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_entry_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->text('value');
            $table->string('filename')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $entries = DB::table('work_entries')
            ->whereNotNull('evidence_type')
            ->whereNotNull('evidence')
            ->where('evidence', '!=', '')
            ->get();

        foreach ($entries as $entry) {
            if ($entry->evidence_type === 'url') {
                DB::table('work_entry_evidences')->insert([
                    'work_entry_id' => $entry->id,
                    'type' => 'url',
                    'value' => $entry->evidence,
                    'filename' => null,
                    'sort_order' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                continue;
            }

            if ($entry->evidence_type !== 'file') {
                continue;
            }

            foreach ($this->decodeFilePaths($entry->evidence) as $sortOrder => $path) {
                DB::table('work_entry_evidences')->insert([
                    'work_entry_id' => $entry->id,
                    'type' => 'file',
                    'value' => $path,
                    'filename' => basename($path),
                    'sort_order' => $sortOrder,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        Schema::table('work_entries', function (Blueprint $table) {
            $table->dropColumn(['evidence', 'evidence_type']);
        });
    }

    public function down(): void
    {
        Schema::table('work_entries', function (Blueprint $table) {
            $table->text('evidence')->nullable();
            $table->string('evidence_type')->nullable();
        });

        $evidences = DB::table('work_entry_evidences')
            ->orderBy('work_entry_id')
            ->orderBy('sort_order')
            ->get()
            ->groupBy('work_entry_id');

        foreach ($evidences as $workEntryId => $rows) {
            $first = $rows->first();

            if ($first->type === 'url') {
                DB::table('work_entries')->where('id', $workEntryId)->update([
                    'evidence_type' => 'url',
                    'evidence' => $first->value,
                ]);

                continue;
            }

            $paths = $rows->where('type', 'file')->pluck('value')->values()->all();

            DB::table('work_entries')->where('id', $workEntryId)->update([
                'evidence_type' => 'file',
                'evidence' => count($paths) === 1 ? $paths[0] : json_encode($paths),
            ]);
        }

        Schema::dropIfExists('work_entry_evidences');
    }

    /**
     * @return list<string>
     */
    private function decodeFilePaths(string $evidence): array
    {
        $decoded = json_decode($evidence, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return array_values(array_filter($decoded, fn ($path) => is_string($path) && $path !== ''));
        }

        return [$evidence];
    }
};
