<?php

use App\Models\BankAccount;
use App\Models\ImportBatch;
use App\Models\Reconciliation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(BankAccount::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Reconciliation::class)->nullable()->constrained()->nullOnDelete();
            $table->foreignIdFor(ImportBatch::class)->nullable()->constrained()->nullOnDelete();

            $table->dateTime('txn_date')->nullable();
            $table->string('servicing_branch')->nullable();
            $table->string('check_no')->nullable();
            $table->string('description')->nullable();
            $table->decimal('debit', 18, 2)->default(0);
            $table->decimal('credit', 18, 2)->default(0);
            $table->decimal('running_balance', 18, 2)->nullable();
            $table->boolean('is_balance_forward')->default(false);

            $table->string('derived_type')->default('other');
            $table->foreignId('matched_check_issuance_id')->nullable();
            $table->string('match_status')->default('unmatched'); // unmatched | auto | manual | ignored

            $table->timestamps();

            $table->index(['bank_account_id', 'check_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_transactions');
    }
};
