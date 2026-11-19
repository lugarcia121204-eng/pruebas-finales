<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rubrics', function (Blueprint $table) {
            $table->string('stage', 16)->default('local');
            $table->index('stage');
            $table->string('active_stage', 16)
                ->nullable()
                ->virtualAs('CASE WHEN is_active AND deleted_at IS NULL THEN stage ELSE NULL END');
            $table->unique('active_stage');
        });
    }

    public function down(): void
    {
        Schema::table('rubrics', function (Blueprint $table) {
            $table->dropUnique(['active_stage']);
            $table->dropIndex(['stage']);
            $table->dropColumn(['active_stage', 'stage']);
        });
    }
};
