<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_listings', function (Blueprint $table) {
            $table->foreignId('category_id')->nullable()->after('role')->constrained('job_categories')->nullOnDelete();
            $table->foreignId('role_id')->nullable()->after('category_id')->constrained('job_roles')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('job_listings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
            $table->dropConstrainedForeignId('role_id');
        });
    }
};
