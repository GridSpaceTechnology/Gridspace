<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CandidateProfile extends Model
{
    protected $fillable = [
        'user_id',
        'current_role',
        'desired_role',
        'desired_category_id',
        'desired_role_id',
        'years_of_experience',
        'industry',
        'employment_type_preference',
        'salary_expectation',
        'work_preference',
        'location_country',
        'availability',
        'greatest_achievement',
        'profile_completion_percentage',
    ];

    protected function casts(): array
    {
        return [
            'salary_expectation' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function desiredCategory(): BelongsTo
    {
        return $this->belongsTo(JobCategory::class, 'desired_category_id');
    }

    public function desiredRole(): BelongsTo
    {
        return $this->belongsTo(JobRole::class, 'desired_role_id');
    }
}
