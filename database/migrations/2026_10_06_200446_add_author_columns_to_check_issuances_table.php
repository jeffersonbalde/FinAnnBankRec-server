<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who typed the check in and who last changed it — the Checks Register is
     * shared, so every user needs to see who is behind each record.
     */
    public function up(): void
    {
        Schema::table('check_issuances', function (Blueprint $table): void {
            $table->foreignIdFor(User::class, 'created_by')->nullable()->after('notes')->constrained('users')->nullOnDelete();
            $table->foreignIdFor(User::class, 'updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('check_issuances', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('updated_by');
            $table->dropConstrainedForeignId('created_by');
        });
    }
};
