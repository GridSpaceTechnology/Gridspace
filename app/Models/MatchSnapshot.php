<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MatchSnapshot extends Model
{
    protected $fillable = [
        'candidate_id',
        'job_id',
        'source',
        'algorithm_version',
        'profile_match_score',
        'recommendation_score',
        'match_status',
        'skills_score',
        'role_score',
        'experience_score',
        'personality_score',
        'work_preference_score',
        'salary_score',
        'education_score',
        'availability_score',
        'matched_skills',
        'missing_skills',
        'data_checksum',
        'scored_at',
    ];

    protected function casts(): array
    {
        return [
            'matched_skills' => 'array',
            'missing_skills' => 'array',
            'scored_at' => 'datetime',
        ];
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'candidate_id');
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class, 'job_id');
    }

    public function band(): string
    {
        return match (true) {
            $this->profile_match_score >= 90 => '90-100',
            $this->profile_match_score >= 80 => '80-89',
            $this->profile_match_score >= 70 => '70-79',
            $this->profile_match_score >= 60 => '60-69',
            $this->profile_match_score >= 40 => '40-59',
            $this->profile_match_score >= 20 => '20-39',
            default => '0-19',
        };
    }
}
