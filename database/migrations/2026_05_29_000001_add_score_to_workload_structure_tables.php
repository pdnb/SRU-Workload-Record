<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workload_groups', function (Blueprint $table) {
            $table->decimal('max_score', 8, 2)->nullable()->after('name');
        });

        Schema::table('workload_mains', function (Blueprint $table) {
            $table->decimal('max_score', 8, 2)->nullable()->after('name');
        });

        Schema::table('workloads', function (Blueprint $table) {
            $table->decimal('score', 8, 2)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('workload_groups', function (Blueprint $table) {
            $table->dropColumn('max_score');
        });

        Schema::table('workload_mains', function (Blueprint $table) {
            $table->dropColumn('max_score');
        });

        Schema::table('workloads', function (Blueprint $table) {
            $table->dropColumn('score');
        });
    }
};
