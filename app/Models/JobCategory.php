<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JobCategory extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'domain_keys',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'domain_keys' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function roles(): HasMany
    {
        return $this->hasMany(JobRole::class, 'category_id');
    }
}
