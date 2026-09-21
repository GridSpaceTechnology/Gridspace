<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidate_profiles', function (Blueprint $table) {
            $table->foreignId('desired_category_id')->nullable()->after('desired_role')->constrained('job_categories')->nullOnDelete();
            $table->foreignId('desired_role_id')->nullable()->after('desired_category_id')->constrained('job_roles')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('candidate_profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('desired_category_id');
            $table->dropConstrainedForeignId('desired_role_id');
        });
    }
};
