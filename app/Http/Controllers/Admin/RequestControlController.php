<?php

namespace App\Http\Controllers\Admin;

use App\Exports\ApplicationsExport;
use App\Http\Controllers\Controller;
use App\Http\Resources\RequestControlSummaryResource;
use App\Models\Application;
use App\Models\Institution;
use App\Services\ApplicationReportFilters;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;

class RequestControlController extends Controller
{
    /**
     * Display a listing of institutions with approved/rejected counts.
     */
    public function index(Request $request)
    {
        $filters = ApplicationReportFilters::validated($request);
        $rows = $request->input('rows', 10);

        $matches = fn ($query) => ApplicationReportFilters::apply($query, $filters);
        $institutions = ApplicationReportFilters::institutions(Institution::with('state'), $filters)
            ->withCount([
                'applications as approved_count' => fn ($query) => $matches($query)->where('applications.status', 'approved'),
                'applications as rejected_count' => fn ($query) => $matches($query)->where('applications.status', 'rejected'),
                'applications as filtered_applications_count' => $matches,
            ])
            ->whereHas('applications', $matches)
            ->orderBy('institutions.name')->orderBy('institutions.id')
            ->paginate($rows)
            ->withQueryString();

        // Get unique states for filter dropdown
        $states = \App\Models\State::orderBy('name')->get(['id', 'name']);

        return Inertia::render('Admin/RequestControl/Index', [
            'institutions' => RequestControlSummaryResource::collection($institutions),
            'states' => $states,
            'filters' => $filters,
            ...ApplicationReportFilters::catalogs(),
        ]);
    }

    public function export(Request $request)
    {
        return Excel::download(ApplicationsExport::fromFilters(ApplicationReportFilters::validated($request)), 'solicitudes_institucion.xlsx');
    }

    /**
     * Display the specified resource details (list of requests for a campus).
     */
    public function show(Request $request, $id)
    {
        $filters = ApplicationReportFilters::validated($request);
        $institution = \App\Models\Institution::with('state')->findOrFail($id);

        $applications = ApplicationReportFilters::apply(Application::whereHas('user', function ($q) use ($id) {
            $q->where('institution_id', $id);
        }), $filters)
            ->with(['user', 'announcement'])
            ->orderByDesc('created_at')
            ->paginate(15)->withQueryString();

        return Inertia::render('Admin/RequestControl/Show', [
            'institution' => $institution,
            'applications' => \App\Http\Resources\ApplicationResource::collection($applications),
            'filters' => $filters,
        ]);
    }
}
