<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The "Locked" status was removed; certified is now the final state.
     */
    public function up(): void
    {
        DB::table('reconciliations')->where('status', 'locked')->update(['status' => 'certified']);
    }

    public function down(): void
    {
        // Cannot tell which certified reconciliations used to be locked.
    }
};
