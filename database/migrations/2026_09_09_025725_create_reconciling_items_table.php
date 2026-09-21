<?php

use App\Models\Reconciliation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciling_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(Reconciliation::class)->constrained()->cascadeOnDelete();
            $table->string('side');       // bank | book
            $table->string('category');   // ReconcilingItemCategory
            $table->string('operation');  // add | deduct
            $table->decimal('amount', 18, 2)->default(0);
            $table->text('explanatory_comment')->nullable();
            $table->string('schedule_no')->nullable();
            $table->boolean('is_auto_generated')->default(false);
            $table->nullableMorphs('source');
            $table->timestamps();

            $table->index(['reconciliation_id', 'side']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciling_items');
    }
};
