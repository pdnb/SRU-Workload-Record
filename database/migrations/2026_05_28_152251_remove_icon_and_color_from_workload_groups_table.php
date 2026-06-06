<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $columns = array_filter(
            ['icon', 'color'],
            fn (string $column) => Schema::hasColumn('workload_groups', $column),
        );

        if ($columns === []) {
            return;
        }

        Schema::table('workload_groups', function (Blueprint $table) use ($columns) {
            $table->dropColumn($columns);
        });
    }

    public function down(): void
    {
        Schema::table('workload_groups', function (Blueprint $table) {
            if (! Schema::hasColumn('workload_groups', 'icon')) {
                $table->string('icon')->nullable()->after('name');
            }

            if (! Schema::hasColumn('workload_groups', 'color')) {
                $table->string('color')->nullable()->after('name');
            }
        });
    }
};
