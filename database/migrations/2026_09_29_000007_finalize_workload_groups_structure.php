<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workload_groups', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fiscal_year_id');
        });

        Schema::table('workload_groups', function (Blueprint $table) {
            $table->unsignedBigInteger('workload_structure_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('workload_groups', function (Blueprint $table) {
            $table->unsignedBigInteger('workload_structure_id')->nullable()->change();
        });

        Schema::table('workload_groups', function (Blueprint $table) {
            $table->foreignId('fiscal_year_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        DB::table('workload_groups')->update([
            'fiscal_year_id' => DB::table('workload_structures')
                ->whereColumn('workload_structures.id', 'workload_groups.workload_structure_id')
                ->select('fiscal_year_id'),
        ]);
    }
};
