<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('evaluation_period_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('draft');
            $table->text('self_assessment')->nullable();
            $table->unsignedTinyInteger('self_score')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_comment')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'evaluation_period_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_records');
    }
};
