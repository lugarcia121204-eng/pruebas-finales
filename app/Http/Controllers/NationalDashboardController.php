<?php

namespace App\Http\Controllers;

use App\Http\Resources\RequestControlSummaryResource;
use App\Models\Institution;
use App\Models\NationalReview;
use App\Models\State;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;

class NationalDashboardController extends Controller
{
    private function assignedApplications(Request $request, $status = null)
    {
        return fn ($query) => $query->where('status', 'approved')->whereHas('nationalReview', fn ($review) => $review
            ->where('administrator_id', $request->user()->id)->when($status, fn ($q) => $q->whereIn('status', (array) $status)));
    }

    private function institutions(Request $request)
    {
        return Institution::with('state')->whereHas('applications', $this->assignedApplications($request))
            ->withCount([
                'applications as applications_count' => $this->assignedApplications($request),
                'applications as approved_count' => $this->assignedApplications($request, 'approved'),
                'applications as rejected_count' => $this->assignedApplications($request, 'rejected'),
            ]);
    }

    private function filters(Request $request): array
    {
        return $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'rows' => ['nullable', 'integer', 'in:5,10,25,50'],
            'state_id' => ['nullable', 'integer', 'exists:states,id'],
            'institution_id' => ['nullable', 'integer', 'exists:institutions,id'],
            'sort_field' => ['nullable', 'in:name,state'],
            'sort_direction' => ['nullable', 'in:asc,desc'],
        ]);
    }

    private function filteredInstitutions(Request $request, array $filters)
    {
        return $this->institutions($request)
            ->whereHas('applications', $this->assignedApplications($request, ['approved', 'rejected']))
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(fn ($q) => $q
                ->where('institutions.name', 'like', "%{$search}%")->orWhere('institutions.id', 'like', "%{$search}%")))
            ->when($filters['state_id'] ?? null, fn ($query, $id) => $query->where('institutions.state_id', $id))
            ->when($filters['institution_id'] ?? null, fn ($query, $id) => $query->where('institutions.id', $id))
            ->leftJoin('states', 'states.id', '=', 'institutions.state_id')
            ->orderBy(($filters['sort_field'] ?? '') === 'state' ? 'states.name' : 'institutions.name', $filters['sort_direction'] ?? 'asc')
            ->orderBy('institutions.id');
    }

    public function administrator(Request $request)
    {
        $filters = $this->filters($request);
        $base = NationalReview::where('administrator_id', $request->user()->id)->whereHas('application', fn ($query) => $query->approved());
        $stats = ['total' => (clone $base)->count()];
        foreach (['pending', 'approved', 'rejected'] as $status) $stats[$status] = (clone $base)->where('status', $status)->count();
        $allInstitutions = $this->institutions($request)->orderBy('name')->get();

        return Inertia::render('NationalDashboard', [
            'stats' => $stats,
            'topInstitutions' => $this->institutions($request)->orderByDesc('applications_count')->orderBy('id')->paginate(5, ['institutions.*'], 'top_page')->withQueryString(),
            'institutions' => RequestControlSummaryResource::collection($this->filteredInstitutions($request, $filters)->paginate($filters['rows'] ?? 10, ['institutions.*'], 'table_page')->withQueryString()),
            'allInstitutions' => $allInstitutions->map->only(['id', 'name', 'state_id']),
            'states' => State::whereIn('id', $allInstitutions->pluck('state_id'))->orderBy('name')->get(['id', 'name']),
            'filters' => $filters,
        ]);
    }

    public function export(Request $request)
    {
        $rows = $this->filteredInstitutions($request, $this->filters($request))->get(['institutions.*'])
            ->map(fn ($institution) => [$institution->state?->name, $institution->name, $institution->approved_count, $institution->rejected_count]);
        return Excel::download(new \App\Exports\NationalDashboardExport($rows), 'resumen_etapa_nacional.xlsx');
    }
}
