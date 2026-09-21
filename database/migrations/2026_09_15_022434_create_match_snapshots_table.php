<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('match_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('candidate_id');
            $table->unsignedBigInteger('job_id');
            $table->string('source', 20);
            $table->unsignedSmallInteger('algorithm_version')->default(0);
            $table->unsignedTinyInteger('profile_match_score')->default(0);
            $table->unsignedTinyInteger('recommendation_score')->default(0);
            $table->string('match_status', 20)->nullable();
            $table->unsignedTinyInteger('skills_score')->default(0);
            $table->unsignedTinyInteger('role_score')->default(0);
            $table->unsignedTinyInteger('experience_score')->default(0);
            $table->unsignedTinyInteger('personality_score')->default(0);
            $table->unsignedTinyInteger('work_preference_score')->default(0);
            $table->unsignedTinyInteger('salary_score')->default(0);
            $table->unsignedTinyInteger('education_score')->default(0);
            $table->unsignedTinyInteger('availability_score')->default(0);
            $table->json('matched_skills')->nullable();
            $table->json('missing_skills')->nullable();
            $table->char('data_checksum', 64)->nullable();
            $table->timestamp('scored_at')->useCurrent();
            $table->timestamps();

            $table->unique(['candidate_id', 'job_id', 'source', 'algorithm_version'], 'match_snapshots_pair_unique');
            $table->index(['scored_at']);
            $table->index(['algorithm_version']);

            $table->foreign('candidate_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('job_id')->references('id')->on('job_listings')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_snapshots');
    }
};
