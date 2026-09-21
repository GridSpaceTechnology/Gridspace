<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employer_recommendation_feedback', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employer_id');
            $table->unsignedBigInteger('candidate_id');
            $table->unsignedBigInteger('job_id')->nullable();
            $table->string('feedback_type', 30);
            $table->boolean('is_relevant')->default(false);
            $table->string('feedback_key', 160)->unique();
            $table->timestamps();

            $table->index(['employer_id', 'feedback_type']);
            $table->index(['candidate_id']);

            $table->foreign('employer_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('candidate_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('job_id')->references('id')->on('job_listings')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employer_recommendation_feedback');
    }
};
