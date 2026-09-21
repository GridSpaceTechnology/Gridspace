<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobRole extends Model
{
    protected $fillable = [
        'category_id',
        'name',
        'slug',
        'alternative_titles',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'alternative_titles' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(JobCategory::class, 'category_id');
    }
}
