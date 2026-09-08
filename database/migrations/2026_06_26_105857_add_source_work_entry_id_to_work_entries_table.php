<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_entries', function (Blueprint $table) {
            $table->foreignId('source_work_entry_id')
                ->nullable()
                ->after('work_record_id')
                ->constrained('work_entries')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('work_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_work_entry_id');
        });
    }
};
