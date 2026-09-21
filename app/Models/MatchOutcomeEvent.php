<?php

namespace App\Models;

use App\Enums\MatchOutcomeEventType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MatchOutcomeEvent extends Model
{
    use HasUuids;

    protected $fillable = [
        'candidate_id',
        'job_id',
        'employer_id',
        'application_id',
        'interview_id',
        'snapshot_id',
        'event_type',
        'event_category',
        'algorithm_version',
        'occurrence_key',
        'context',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'event_type' => MatchOutcomeEventType::class,
            'context' => 'array',
            'occurred_at' => 'datetime',
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

    public function employer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employer_id');
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(JobApplication::class, 'application_id');
    }

    public function interview(): BelongsTo
    {
        return $this->belongsTo(Interview::class, 'interview_id');
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(MatchSnapshot::class, 'snapshot_id');
    }
}
