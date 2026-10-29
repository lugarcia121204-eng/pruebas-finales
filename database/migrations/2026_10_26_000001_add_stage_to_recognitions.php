<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('recognitions', 'stage')) {
            Schema::table('recognitions', fn (Blueprint $table) => $table->string('stage', 20)->default('national'));
        }
        // Create the replacement index before removing the index used by the user foreign key.
        if (!Schema::hasIndex('recognitions', 'recognitions_participant_stage_unique')) {
            Schema::table('recognitions', fn (Blueprint $table) => $table->unique(['user_id', 'announcement_id', 'type', 'stage'], 'recognitions_participant_stage_unique'));
        }
        if (Schema::hasIndex('recognitions', 'recognitions_user_id_announcement_id_type_unique')) {
            Schema::table('recognitions', fn (Blueprint $table) => $table->dropUnique(['user_id', 'announcement_id', 'type']));
        }
    }

    public function down(): void
    {
        // Fail rather than discard certificates if both stages already exist.
        Schema::table('recognitions', function (Blueprint $table) {
            $table->unique(['user_id', 'announcement_id', 'type']);
            $table->dropUnique('recognitions_participant_stage_unique');
            $table->dropColumn('stage');
        });
    }
};
