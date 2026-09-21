<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('match_outcome_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('candidate_id')->nullable();
            $table->unsignedBigInteger('job_id')->nullable();
            $table->unsignedBigInteger('employer_id')->nullable();
            $table->unsignedBigInteger('application_id')->nullable();
            $table->unsignedBigInteger('interview_id')->nullable();
            $table->unsignedBigInteger('snapshot_id')->nullable();
            $table->string('event_type', 40);
            $table->string('event_category', 20);
            $table->unsignedSmallInteger('algorithm_version')->default(0);
            $table->string('occurrence_key', 180)->unique();
            $table->json('context')->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamps();

            $table->index(['event_type', 'occurred_at']);
            $table->index(['candidate_id', 'job_id']);
            $table->index(['job_id', 'event_type']);
            $table->index(['algorithm_version', 'event_type']);

            $table->foreign('candidate_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('job_id')->references('id')->on('job_listings')->nullOnDelete();
            $table->foreign('employer_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('application_id')->references('id')->on('applications')->nullOnDelete();
            $table->foreign('interview_id')->references('id')->on('interviews')->nullOnDelete();
            $table->foreign('snapshot_id')->references('id')->on('match_snapshots')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_outcome_events');
    }
};
