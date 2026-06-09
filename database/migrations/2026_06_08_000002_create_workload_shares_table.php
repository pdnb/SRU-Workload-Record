<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workload_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_record_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workload_id')->constrained()->cascadeOnDelete();
            $table->uuid('token')->unique();
            $table->timestamp('enabled_at')->nullable();
            $table->timestamps();

            $table->unique(['work_record_id', 'workload_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workload_shares');
    }
};
