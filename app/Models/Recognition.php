<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Recognition extends Model
{
    protected static function booted(): void
    {
        static::addGlobalScope('admin_institution', function (\Illuminate\Database\Eloquent\Builder $query) {
            if (\App\Services\AdminInstitutionAccess::restricted()) {
                $query->whereIn('recognitions.user_id', \Illuminate\Support\Facades\DB::table('users')->select('id')->where('institution_id', \App\Services\AdminInstitutionAccess::institutionId()));
            }
        });
    }

    use HasFactory;

    protected $table = 'recognitions';

    protected $fillable = [
        'user_id',
        'announcement_id',
        'type',
        'stage',
        'active',
        'activation_override',
        'sent_at',
        'identifier',
        'digital_seal',
        'template_id',
        'snapshot_data',
        'file_path',
    ];

    protected $casts = [
        'active'        => 'boolean',
        'activation_override' => 'boolean',
        'sent_at'       => 'datetime',
        'snapshot_data' => 'array',   // Auto JSON decode/encode
    ];

    // Relaciones

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function announcement()
    {
        return $this->belongsTo(Announcement::class, 'announcement_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    /**
     * Scope a query to find a recognition by its unique identifier.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $identifier
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeValidByIdentifier($query, $identifier)
    {
        return $query->publishable()->where('identifier', $identifier)->with('user');
    }

    public function scopePublishable($query)
    {
        return $query->where('recognitions.active', true)->where(function ($allowed) {
            $allowed->where('recognitions.type', 'evaluator')->orWhere(function ($teacher) {
                $teacher->where('recognitions.type', 'postulante')
                    ->where('recognitions.stage', 'national')
                    ->whereExists(Application::finallyApproved()->selectRaw('1')
                        ->whereColumn('applications.user_id', 'recognitions.user_id')
                        ->whereColumn('applications.announcement_id', 'recognitions.announcement_id')->toBase())
                    ->whereHas('announcement.calendar', fn ($calendar) => $calendar->where('results_start', '<=', now()));
            });
        });
    }
}
