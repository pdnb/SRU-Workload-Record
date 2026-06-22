<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->where('role', 'supervisor')->update(['role' => 'staff']);
    }

    public function down(): void
    {
        // ponytail: cannot restore which users were supervisors
    }
};
