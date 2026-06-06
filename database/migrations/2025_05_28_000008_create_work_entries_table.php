<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_record_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workload_id')->constrained()->cascadeOnDelete();
            $table->text('detail');
            $table->text('result')->nullable();
            $table->decimal('hours', 5, 1)->default(0);
            $table->string('evidence')->nullable();
            $table->string('evidence_type')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_entries');
    }
};
