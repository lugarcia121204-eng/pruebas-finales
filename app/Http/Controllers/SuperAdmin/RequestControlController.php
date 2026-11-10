<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Exports\ApplicationsExport;
use App\Http\Controllers\Controller;
use App\Http\Resources\RequestControlSummaryResource;
use App\Models\Application;
use App\Models\Institution;
use App\Models\State;
use App\Services\ApplicationReportFilters;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;

class RequestControlController extends Controller
{
    public function index(Request $request)
    {
        $filters = ApplicationReportFilters::validated($request);
        $matches = fn ($query) => ApplicationReportFilters::apply($query, $filters);
        $institutions = ApplicationReportFilters::institutions(Institution::with('state'), $filters)
            ->whereHas('applications', $matches)
            ->withCount([
                'applications as approved_count' => fn ($q) => $matches($q)->where('applications.status', 'approved'),
                'applications as rejected_count' => fn ($q) => $matches($q)->where('applications.status', 'rejected'),
                'applications as filtered_applications_count' => $matches,
            ]);
        if (($filters['sort_field'] ?? '') === 'state') {
            $institutions->join('states', 'institutions.state_id', '=', 'states.id')->select('institutions.*')
                ->orderBy('states.name', $filters['sort_direction'] ?? 'asc');
        } else {
            $institutions->orderBy('institutions.'.($filters['sort_field'] ?? 'name'), $filters['sort_direction'] ?? 'asc');
        }

        return Inertia::render('SuperAdmin/Applications/Index', [
            'stats' => ['total' => Application::count(), 'pending' => Application::pending()->count(), 'approved' => Application::approved()->count(), 'rejected' => Application::rejected()->count()],
            'institutions' => RequestControlSummaryResource::collection($institutions->orderBy('institutions.id')->paginate($filters['rows'] ?? 10)->withQueryString()),
            'states' => State::orderBy('name')->get(['id', 'name']),
            'allInstitutions' => Institution::orderBy('name')->get(['id', 'name', 'state_id']),
            'filters' => $filters,
            ...ApplicationReportFilters::catalogs(),
        ]);
    }

    public function export(Request $request)
    {
        return Excel::download(ApplicationsExport::fromFilters(ApplicationReportFilters::validated($request)), 'control_solicitudes_'.now()->format('Y-m-d').'.xlsx');
    }
}
