<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('candidate_job_interactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('job_id')->constrained('job_listings')->cascadeOnDelete();
            $table->string('interaction_type', 16);
            $table->timestamp('acted_at');
            $table->timestamps();

            $table->index(['user_id', 'interaction_type', 'acted_at'], 'candidate_job_interactions_user_type_acted_idx');
            $table->index(['user_id', 'job_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('candidate_job_interactions');
    }
};
