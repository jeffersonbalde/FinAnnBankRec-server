<?php

use App\Models\BankAccount;
use App\Models\Reconciliation;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(Reconciliation::class)->nullable()->constrained()->cascadeOnDelete();
            $table->foreignIdFor(BankAccount::class)->nullable()->constrained()->nullOnDelete();
            $table->string('type'); // rci | bank_statement
            $table->string('original_filename');
            $table->string('stored_path')->nullable();
            $table->foreignIdFor(User::class, 'uploaded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('status')->default('parsed'); // parsed | committed | failed
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('imported_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->json('parsed_preview')->nullable(); // normalised rows for preview
            $table->json('meta')->nullable();           // header info pulled from the file
            $table->json('error_log')->nullable();      // [{row, message}]
            $table->timestamp('committed_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_batches');
    }
};
