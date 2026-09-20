<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('corevisys_license_cache')) {
            return;
        }

        Schema::table('corevisys_license_cache', function (Blueprint $table) {
            if (! Schema::hasColumn('corevisys_license_cache', 'issued_at')) {
                $table->timestamp('issued_at')->nullable();
            }
            if (! Schema::hasColumn('corevisys_license_cache', 'offline_valid_until')) {
                $table->timestamp('offline_valid_until')->nullable();
            }
            if (! Schema::hasColumn('corevisys_license_cache', 'is_grace_period')) {
                $table->boolean('is_grace_period')->default(false);
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('corevisys_license_cache')) {
            return;
        }

        Schema::table('corevisys_license_cache', function (Blueprint $table) {
            foreach (['issued_at', 'offline_valid_until', 'is_grace_period'] as $column) {
                if (Schema::hasColumn('corevisys_license_cache', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
