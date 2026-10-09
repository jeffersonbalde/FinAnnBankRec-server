<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A user "clearing" their own activity hides it from their list (hidden_at) while the
     * audit copy stays for the administrator; `activity_retention_days` is their own
     * auto-clear schedule. Each step checks first, so a half-applied run can be repeated.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('audit_logs', 'hidden_at')) {
            Schema::table('audit_logs', function (Blueprint $table) {
                $table->timestamp('hidden_at')->nullable()->after('ip_address');
            });
        }

        if (! Schema::hasIndex('audit_logs', 'audit_logs_user_visible_idx')) {
            Schema::table('audit_logs', function (Blueprint $table) {
                $table->index(['user_id', 'hidden_at', 'created_at'], 'audit_logs_user_visible_idx');
            });
        }

        if (! Schema::hasColumn('users', 'activity_retention_days')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unsignedSmallInteger('activity_retention_days')->nullable()->after('is_active');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'activity_retention_days')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('activity_retention_days');
            });
        }

        if (Schema::hasIndex('audit_logs', 'audit_logs_user_visible_idx')) {
            Schema::table('audit_logs', function (Blueprint $table) {
                $table->dropIndex('audit_logs_user_visible_idx');
            });
        }

        if (Schema::hasColumn('audit_logs', 'hidden_at')) {
            Schema::table('audit_logs', function (Blueprint $table) {
                $table->dropColumn('hidden_at');
            });
        }
    }
};
