<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<string, string|null> table => parent column that scopes external_id */
    private const STRUCTURE_TABLES = [
        'workload_structures' => null,
        'workload_groups' => 'workload_structure_id',
        'workload_mains' => 'workload_group_id',
        'workloads' => 'workload_main_id',
    ];

    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->string('external_id')->nullable()->unique()->after('id');
            $table->timestamp('synced_at')->nullable();
        });

        foreach (self::STRUCTURE_TABLES as $tableName => $parentColumn) {
            Schema::table($tableName, function (Blueprint $table) use ($parentColumn) {
                $table->string('external_id')->nullable()->after('id');
                $table->boolean('is_active')->default(true);
                $table->timestamp('synced_at')->nullable();

                if ($parentColumn !== null) {
                    $table->unique([$parentColumn, 'external_id']);
                }
            });
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('external_id')->nullable()->unique()->after('id');
            $table->string('position_source')->default('oracle')->after('position_id');
            $table->timestamp('position_synced_at')->nullable()->after('position_source');
        });

        Schema::create('oracle_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->string('status');
            $table->boolean('dry_run')->default(false);
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->json('stats')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oracle_sync_runs');

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['external_id']);
            $table->dropColumn(['external_id', 'position_source', 'position_synced_at']);
        });

        foreach (self::STRUCTURE_TABLES as $tableName => $parentColumn) {
            Schema::table($tableName, function (Blueprint $table) use ($parentColumn) {
                if ($parentColumn !== null) {
                    $table->dropUnique([$parentColumn, 'external_id']);
                }

                $table->dropColumn(['external_id', 'is_active', 'synced_at']);
            });
        }

        Schema::table('positions', function (Blueprint $table) {
            $table->dropUnique(['external_id']);
            $table->dropColumn(['external_id', 'synced_at']);
        });
    }
};
