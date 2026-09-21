<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CandidateRecommendationFeedback extends Model
{
    protected $table = 'candidate_recommendation_feedback';

    protected $fillable = [
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

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'candidate_id');
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class, 'job_id');
    }

    public function isNegativeSignal(): bool
    {
        return ! $this->is_relevant
            && in_array($this->feedback_type, [
                'not_relevant',
                'not_interested',
                'wrong_role',
                'wrong_location',
                'wrong_experience',
            ], true);
    }
}
