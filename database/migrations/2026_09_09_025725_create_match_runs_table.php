<?php

use App\Models\Reconciliation;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('match_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(Reconciliation::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(User::class, 'run_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('matched_count')->default(0);
            $table->unsignedInteger('outstanding_count')->default(0);
            $table->unsignedInteger('flagged_count')->default(0);
            $table->json('flags')->nullable();       // [{type, message, ...}]
            $table->json('parameters')->nullable();  // {amount_tolerance: ...}
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_runs');
    }
};
