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
        Schema::create('check_issuances', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(BankAccount::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Reconciliation::class)->nullable()->constrained()->nullOnDelete();
            $table->foreignIdFor(ImportBatch::class)->nullable()->constrained()->nullOnDelete();

            $table->date('check_date')->nullable();
            $table->string('serial_no');
            $table->string('dv_no')->nullable();
            $table->string('or_burs_no')->nullable();
            $table->string('responsibility_center_code')->nullable();
            $table->string('payee');
            $table->string('uacs_object_code')->nullable();
            $table->text('nature_of_payment')->nullable();
            $table->decimal('amount', 18, 2);
            $table->decimal('gross_taxable_amount', 18, 2)->nullable();
            $table->decimal('withholding_tax', 18, 2)->nullable();
            $table->string('report_no')->nullable();

            $table->string('status')->default('outstanding'); // outstanding | cleared | cancelled | stale
            $table->date('cleared_on')->nullable();
            $table->foreignId('cleared_bank_transaction_id')->nullable();
            $table->string('cancelled_reason')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique(['bank_account_id', 'serial_no']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('check_issuances');
    }
};
