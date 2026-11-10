<?php

namespace App\Services;

use App\Models\PriorityArea;
use App\Models\SubArea;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ApplicationReportFilters
{
    public static function validated(Request $request): array
    {
        $subArea = Rule::exists('sub_areas', 'id');
        if ($request->filled('priority_area_id')) {
            $subArea->where('priority_area_id', $request->input('priority_area_id'));
        }

        return $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:255'],
            'state_id' => ['nullable', 'integer', 'exists:states,id'],
            'institution_id' => ['nullable', 'integer', 'exists:institutions,id'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('date_from') ? ['after_or_equal:date_from'] : [])],
            'priority_area_id' => ['nullable', 'integer', 'exists:priority_areas,id'],
            'sub_area_id' => ['nullable', 'integer', $subArea],
            'status' => ['nullable', 'in:pending,in_review,approved,rejected'],
            'rows' => ['nullable', 'integer', 'in:5,10,15,25,50,100'],
            'sort_field' => ['nullable', 'in:name,state,id'],
            'sort_direction' => ['nullable', 'in:asc,desc'],
        ]);
    }

    public static function apply(Builder $query, array $filters, bool $withStatus = true): Builder
    {
        $query->when($filters['date_from'] ?? null, fn ($q, $date) => $q->whereDate('applications.created_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($q, $date) => $q->whereDate('applications.created_at', '<=', $date))
            ->when($filters['priority_area_id'] ?? null, fn ($q, $id) => $q->whereHas('user', fn ($u) => $u->where('priority_area_id', $id)))
            ->when($filters['sub_area_id'] ?? null, fn ($q, $id) => $q->whereHas('user', fn ($u) => $u->where('sub_area_id', $id)));
        if ($withStatus) {
            $query->whereIn('applications.status', empty($filters['status']) ? ['approved', 'rejected'] : [$filters['status']]);
        }

        return $query;
    }

    public static function institutions(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['search'] ?? null, fn ($q, $text) => $q->where(fn ($match) => $match
                ->where('institutions.name', 'like', "%{$text}%")->orWhere('institutions.id', 'like', "%{$text}%")
                ->orWhereHas('state', fn ($s) => $s->where('name', 'like', "%{$text}%"))))
            ->when($filters['state_id'] ?? null, fn ($q, $id) => $q->where('institutions.state_id', $id))
            ->when($filters['state'] ?? null, fn ($q, $name) => $q->whereHas('state', fn ($s) => $s->where('name', $name)))
            ->when($filters['institution_id'] ?? null, fn ($q, $id) => $q->where('institutions.id', $id));
    }

    public static function catalogs(): array
    {
        return [
            'priorityAreas' => PriorityArea::orderBy('name')->get(['id', 'name']),
            'subAreas' => SubArea::orderBy('name')->get(['id', 'name', 'priority_area_id']),
        ];
    }
}
