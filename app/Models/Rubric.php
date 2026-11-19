<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Rubric extends Model
{
    use HasFactory, SoftDeletes;

    public const LOCAL = 'local';
    public const NATIONAL = 'national';
    public const STAGES = [self::LOCAL, self::NATIONAL];

    protected $fillable = ['title', 'stage', 'is_active'];

    protected $hidden = ['active_stage'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function questions(): HasMany
    {
        return $this->hasMany(RubricQuestion::class);
    }

    public function scopeForStage($query, string $stage)
    {
        return $query->where('stage', $stage);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
