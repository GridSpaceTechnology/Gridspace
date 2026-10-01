<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidate_recommendation_feedback', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('candidate_id');
            $table->unsignedBigInteger('job_id');
            $table->string('feedback_type', 30);
            $table->boolean('is_relevant')->default(false);
            $table->string('feedback_key', 160)->unique();
            $table->timestamps();

            $table->index(['candidate_id', 'feedback_type'], 'crf_candidate_type_index');
            $table->index(['job_id']);

            $table->foreign('candidate_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('job_id')->references('id')->on('job_listings')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_recommendation_feedback');
    }
};
