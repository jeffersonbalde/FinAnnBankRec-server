<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Checks that came in through an RCI import before authorship was tracked
     * are credited to whoever uploaded that import.
     */
    public function up(): void
    {
        DB::table('check_issuances')
            ->whereNull('created_by')
            ->whereNotNull('import_batch_id')
            ->update([
                'created_by' => DB::raw('(select uploaded_by from import_batches where import_batches.id = check_issuances.import_batch_id)'),
            ]);
    }

    public function down(): void
    {
        // Forward-only data fix; nothing to undo.
    }
};
