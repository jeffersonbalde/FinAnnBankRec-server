<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A person's own auto-delete schedule for notifications they have already read
     * (null = never). Checks first, so a half-applied run can be repeated.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'notification_retention_days')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unsignedSmallInteger('notification_retention_days')->nullable()->after('activity_retention_days');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'notification_retention_days')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('notification_retention_days');
            });
        }
    }
};
