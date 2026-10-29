<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('recognitions', fn (Blueprint $table) => $table->boolean('activation_override')->nullable());
    }

    public function down(): void
    {
        Schema::table('recognitions', fn (Blueprint $table) => $table->dropColumn('activation_override'));
    }
};
