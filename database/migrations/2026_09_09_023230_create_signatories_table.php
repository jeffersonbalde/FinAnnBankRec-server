<?php

use App\Models\BankAccount;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signatories', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(BankAccount::class)->constrained()->cascadeOnDelete();
            $table->string('block'); // prepared_by | certified_correct | disbursing_officer
            $table->string('name');
            $table->string('designation');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signatories');
    }
};
