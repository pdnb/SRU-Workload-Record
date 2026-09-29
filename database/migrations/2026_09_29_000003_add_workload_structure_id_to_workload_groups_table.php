<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workload_groups', function (Blueprint $table) {
            $table->foreignId('workload_structure_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('workload_groups', function (Blueprint $table) {
            $table->dropConstrainedForeignId('workload_structure_id');
        });
    }
};
