<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('position')->nullable()->after('password');
            $table->string('department')->nullable()->after('position');
            $table->string('faculty')->nullable()->after('department');
            $table->string('role')->default('staff')->after('faculty');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['position', 'department', 'faculty', 'role']);
        });
    }
};
