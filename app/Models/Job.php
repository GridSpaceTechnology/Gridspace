<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Job extends Model
{
    protected $table = 'job_listings';

    protected $fillable = [
        'employer_id',
        'company_id',
        'title',
        'slug',
        'role',
        'category_id',
        'role_id',
        'location',
        'location_country',
        'description',
        'responsibilities',
        'requirements',
        'benefits',
        'industry',
        'employment_type',
        'salary_min',
        'salary_max',
        'salary_currency',
        'salary_period',
        'salary_visible',
        'work_preference',
        'minimum_experience',
        'experience_level',
        'required_skills_json',
        'personality_preferences_json',
        'temperament_preference',
        'status',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'salary_min' => 'decimal:2',
            'salary_max' => 'decimal:2',
            'salary_visible' => 'boolean',
            'required_skills_json' => 'array',
            'personality_preferences_json' => 'array',
            'published_at' => 'datetime',
        ];
    }

    public function employer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employer_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function professionalCategory(): BelongsTo
    {
        return $this->belongsTo(JobCategory::class, 'category_id');
    }

    public function professionalRole(): BelongsTo
    {
        return $this->belongsTo(JobRole::class, 'role_id');
    }

    public function jobSkills(): HasMany
    {
        return $this->hasMany(JobSkill::class);
    }

    public function requiredSkills(): HasMany
    {
        return $this->hasMany(JobSkill::class)->where('is_required', true);
    }

    public function jobRequirements(): HasMany
    {
        return $this->hasMany(JobRequirement::class);
    }

    public function mandatoryRequirements(): HasMany
    {
        return $this->hasMany(JobRequirement::class)->where('is_mandatory', true);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(JobApplication::class);
    }

    public function shortlists(): HasMany
    {
        return $this->hasMany(EmployerShortlist::class);
    }

    public function interviews(): HasMany
    {
        return $this->hasMany(Interview::class);
    }

    public function getRequiredSkills(): array
    {
        return $this->required_skills_json ?? [];
    }

    public function getLocationAttribute(): ?string
    {
        return $this->attributes['location'];
    }

    public function getExperienceRequiredAttribute(): int
    {
        return $this->minimum_experience ?? 0;
    }

    public function getJobDescriptionAttribute(): string
    {
        return $this->description ?? '';
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function isClosed(): bool
    {
        return in_array($this->status, ['closed', 'filled']);
    }

    public static function supportedCurrencies(): array
    {
        return array_keys(config('currencies.currencies', []));
    }

    public static function salaryPeriods(): array
    {
        return config('currencies.salary_periods', []);
    }

    public function currencySymbol(): string
    {
        return config("currencies.currencies.{$this->salary_currency}.symbol", '');
    }

    public function hasSalary(): bool
    {
        return (float) ($this->salary_min ?? 0) > 0 || (float) ($this->salary_max ?? 0) > 0;
    }

    public function salaryLabel(): ?string
    {
        if (! $this->hasSalary()) {
            return null;
        }

        $symbol = $this->currencySymbol();
        $min = $this->salary_min !== null ? number_format((float) $this->salary_min) : null;
        $max = $this->salary_max !== null ? number_format((float) $this->salary_max) : null;

        if ($min && $max) {
            $range = "{$symbol}{$min} – {$symbol}{$max}";
        } else {
            $range = $symbol.($max ?: $min);
        }

        $period = self::salaryPeriods()[$this->salary_period] ?? null;

        return $period !== null
            ? "{$range} / {$period}"
            : $range;
    }
}
