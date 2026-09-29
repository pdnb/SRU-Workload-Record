<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workload_structures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_year_id')->constrained()->cascadeOnDelete();
            $table->foreignId('position_id')->constrained()->restrictOnDelete();
            $table->string('name')->nullable();
            $table->timestamps();

            $table->unique(['fiscal_year_id', 'position_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workload_structures');
    }
};
