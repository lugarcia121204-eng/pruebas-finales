<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calendars', function (Blueprint $table) {
            $table->date('national_evaluation_start')->nullable();
            $table->date('national_evaluation_end')->nullable();
            $table->date('national_results_start')->nullable();
            $table->date('national_results_end')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('calendars', function (Blueprint $table) {
            $table->dropColumn([
                'national_evaluation_start',
                'national_evaluation_end',
                'national_results_start',
                'national_results_end',
            ]);
        });
    }
};
