<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Calendar extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'calendars';

    protected $fillable = [
        'announcement_id',
        'publication_start',
        'registration_start',
        'registration_end',
        'evaluation_start',
        'evaluation_end',
        'results_start',
        'results_end',
        'national_evaluation_start',
        'national_evaluation_end',
        'national_results_start',
        'national_results_end',
    ];

    protected $casts = [
        'publication_start' => 'date',
        'registration_start' => 'date',
        'registration_end' => 'date',
        'evaluation_start' => 'date',
        'evaluation_end' => 'date',
        'results_start' => 'date',
        'results_end' => 'date',
        'national_evaluation_start' => 'date',
        'national_evaluation_end' => 'date',
        'national_results_start' => 'date',
        'national_results_end' => 'date',
    ];

    public function localEvaluationIsOpen(): bool
    {
        return $this->evaluation_start && $this->evaluation_end
            && today()->betweenIncluded($this->evaluation_start, $this->evaluation_end);
    }

    public function scopeWithOpenLocalEvaluation($query)
    {
        return $query->whereDate('evaluation_start', '<=', today())
            ->whereDate('evaluation_end', '>=', today());
    }

    public function nationalEvaluationIsOpen(): bool
    {
        $today = today();

        return $this->national_evaluation_start && $this->national_evaluation_end
            && $today->betweenIncluded($this->national_evaluation_start, $this->national_evaluation_end);
    }

    public function finalResultsArePublished(): bool
    {
        $start = $this->national_results_start ?? ($this->national_evaluation_start ? null : $this->results_start);

        return $start !== null && today()->gte($start);
    }

    public function scopeWithPublishedFinalResults($query)
    {
        return $query->whereDate(
            $query->getQuery()->raw('COALESCE(national_results_start, CASE WHEN national_evaluation_start IS NULL THEN results_start END)'),
            '<=',
            today()
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Relaciones
    |--------------------------------------------------------------------------
    */

    public function announcement(): BelongsTo
    {
        return $this->belongsTo(Announcement::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Scope para búsqueda global en calendarios.
     */
    public function scopeBuscarGlobal(\Illuminate\Database\Eloquent\Builder $query, ?string $search): \Illuminate\Database\Eloquent\Builder
    {
        if (!$search) {
            return $query;
        }

        return $query->whereHas('announcement', function ($q) use ($search) {
            $q->where('name', 'LIKE', "%{$search}%");
        });
    }

    /**
     * Scope para ordenar resultados.
     */
    public function scopeOrdenado(\Illuminate\Database\Eloquent\Builder $query, string $sortField = 'id', string $sortDirection = 'desc'): \Illuminate\Database\Eloquent\Builder
    {
        return $query->orderBy($sortField, $sortDirection);
    }
}
