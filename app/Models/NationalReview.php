<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NationalReview extends Model
{
    protected $fillable = ['application_id', 'administrator_id', 'assigned_by', 'status', 'comment', 'decided_at'];

    protected $casts = ['decided_at' => 'datetime'];

    public function evaluations()
    {
        return $this->hasMany(NationalEvaluation::class);
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function administrator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'administrator_id');
    }
}
