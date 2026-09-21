<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployerRecommendationFeedback extends Model
{
    protected $table = 'employer_recommendation_feedback';

    protected $fillable = [
        'employer_id',
        'candidate_id',
        'job_id',
        'feedback_type',
        'is_relevant',
        'feedback_key',
    ];

    protected function casts(): array
    {
        return [
            'is_relevant' => 'boolean',
        ];
    }

    public function employer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employer_id');
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'candidate_id');
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class, 'job_id');
    }
}
