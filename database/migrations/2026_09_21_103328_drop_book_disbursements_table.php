<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('book_disbursements');

        DB::table('import_batches')->where('type', 'book_disbursement')->delete();
    }

    public function down(): void
    {
        Schema::create('book_disbursements', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
        });
    }
};
