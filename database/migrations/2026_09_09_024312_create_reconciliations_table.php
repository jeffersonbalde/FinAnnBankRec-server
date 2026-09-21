<?php

use App\Models\BankAccount;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliations', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(BankAccount::class)->constrained()->cascadeOnDelete();
            $table->string('period_type')->default('monthly'); // weekly | monthly
            $table->date('period_start');
            $table->date('period_end');
            $table->string('statement_label')->nullable(); // e.g. "As of July 31, 2026"
            $table->string('report_no')->nullable();

            $table->decimal('unadjusted_book_balance', 18, 2)->default(0);
            $table->decimal('unadjusted_bank_balance', 18, 2)->default(0);
            $table->decimal('adjusted_book_balance', 18, 2)->default(0);
            $table->decimal('adjusted_bank_balance', 18, 2)->default(0);
            $table->decimal('difference', 18, 2)->default(0);

            $table->string('status')->default('draft');
            $table->foreignIdFor(User::class, 'prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignIdFor(User::class, 'reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('prepared_at')->nullable();
            $table->timestamp('certified_at')->nullable();
            $table->text('review_remarks')->nullable();

            $table->timestamps();

            $table->unique(['bank_account_id', 'period_start', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliations');
    }
};
