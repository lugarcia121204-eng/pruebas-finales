<?php

namespace App\Http\Controllers;

use App\Http\Resources\RequestControlSummaryResource;
use App\Models\Institution;
use App\Models\NationalReview;
use App\Models\State;
use App\Services\ApplicationReportFilters;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;

class NationalDashboardController extends Controller
{
    private function assignedApplications(Request $request, $status = null, array $filters = [])
    {
        return fn ($query) => ApplicationReportFilters::apply($query, $filters, false)->where('applications.status', 'approved')->whereHas('nationalReview', fn ($review) => $review
            ->where('administrator_id', $request->user()->id)->when($status !== null, fn ($q) => $q->whereIn('status', (array) $status)));
    }

    private function institutions(Request $request, array $filters = [])
    {
        return Institution::with('state')->whereHas('applications', $this->assignedApplications($request, null, $filters))
            ->withCount([
                'applications as applications_count' => $this->assignedApplications($request, null, $filters),
                'applications as approved_count' => $this->assignedApplications($request, empty($filters['status']) ? ['approved'] : array_intersect(['approved'], [$filters['status']]), $filters),
                'applications as rejected_count' => $this->assignedApplications($request, empty($filters['status']) ? ['rejected'] : array_intersect(['rejected'], [$filters['status']]), $filters),
                'applications as filtered_applications_count' => $this->assignedApplications($request, $filters['status'] ?? ['approved', 'rejected'], $filters),
            ]);
    }

    private function filters(Request $request): array
    {
        $filters = ApplicationReportFilters::validated($request);
        $request->validate(['status' => ['nullable', 'in:pending,approved,rejected']]);

        return $filters;
    }

    private function filteredInstitutions(Request $request, array $filters)
    {
        return $this->institutions($request, $filters)
            ->whereHas('applications', $this->assignedApplications($request, $filters['status'] ?? ['approved', 'rejected'], $filters))
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
        foreach (['pending', 'approved', 'rejected'] as $status) {
            $stats[$status] = (clone $base)->where('status', $status)->count();
        }
        $allInstitutions = $this->institutions($request)->orderBy('name')->get();

        return Inertia::render('NationalDashboard', [
            'stats' => $stats,
            'topInstitutions' => $this->institutions($request)->orderByDesc('applications_count')->orderBy('id')->paginate(5, ['institutions.*'], 'top_page')->withQueryString(),
            'institutions' => RequestControlSummaryResource::collection($this->filteredInstitutions($request, $filters)->paginate($filters['rows'] ?? 10, ['institutions.*'], 'table_page')->withQueryString()),
            'allInstitutions' => $allInstitutions->map->only(['id', 'name', 'state_id']),
            'states' => State::whereIn('id', $allInstitutions->pluck('state_id'))->orderBy('name')->get(['id', 'name']),
            'filters' => $filters,
            ...ApplicationReportFilters::catalogs(),
        ]);
    }

    public function export(Request $request)
    {
        $rows = $this->filteredInstitutions($request, $this->filters($request))->get(['institutions.*'])
            ->map(fn ($institution) => [$institution->state?->name, $institution->name, $institution->approved_count, $institution->rejected_count, $institution->filtered_applications_count]);

        return Excel::download(new \App\Exports\NationalDashboardExport($rows), 'resumen_etapa_nacional.xlsx');
    }
}
