<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('national_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('national_review_id')->constrained()->cascadeOnDelete();
            $table->foreignId('evaluator_id')->constrained('users')->restrictOnDelete();
            $table->string('status')->default('pending');
            $table->decimal('score', 8, 2)->nullable();
            $table->json('answers')->nullable();
            $table->foreignId('rubric_id')->nullable()->constrained('rubrics')->restrictOnDelete();
            $table->text('comment')->nullable();
            $table->timestamps();
            $table->unique(['national_review_id', 'evaluator_id']);
        });
    }
    public function down(): void { Schema::dropIfExists('national_evaluations'); }
};
