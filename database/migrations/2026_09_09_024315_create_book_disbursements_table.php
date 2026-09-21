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
        Schema::create('book_disbursements', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(BankAccount::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Reconciliation::class)->nullable()->constrained()->nullOnDelete();
            $table->foreignIdFor(ImportBatch::class)->nullable()->constrained()->nullOnDelete();

            $table->date('txn_date')->nullable();
            $table->string('dv_no')->nullable();
            $table->string('check_no')->nullable();
            $table->string('payee')->nullable();
            $table->decimal('amount', 18, 2);
            $table->string('type')->default('disbursement'); // disbursement | collection | adjustment
            $table->boolean('recorded_in_books')->default(true);
            $table->text('notes')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('book_disbursements');
    }
};
