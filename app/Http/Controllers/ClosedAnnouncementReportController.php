<?php

namespace App\Http\Controllers;

use App\Models\{Announcement, Application};
use Illuminate\Http\Request;
use Inertia\Inertia;

class ClosedAnnouncementReportController extends Controller
{
    private function applications(Request $request)
    {
        $user = $request->user();
        $query = Application::query();
        if ($user->isSuperAdmin()) return $query;
        if ($user->hasRole('Admin Nacional')) {
            return $query->whereHas('nationalReview', fn ($review) => $review->where('administrator_id', $user->id));
        }
        return $query->whereHas('user', fn ($teacher) => $teacher->where('institution_id', $user->institution_id ?: 0));
    }

    private function announcements(Request $request)
    {
        return Announcement::cerrada()->when(!$request->user()->isSuperAdmin(), fn ($query) => $query->whereIn('id',
            $this->applications($request)->select('applications.announcement_id')));
    }

    public function download(Request $request, int $announcement, string $stage)
    {
        abort_unless(in_array($stage, ['local', 'national'], true), 404);
        $user = $request->user();
        if (!$user->isSuperAdmin()) {
            abort_unless($user->hasRole('Admin Nacional') ? $stage === 'national' : $stage === 'local', 403);
        }
        $selected = $this->announcements($request)->findOrFail($announcement);
        $applications = $this->applications($request)->where('announcement_id', $selected->id)->approved()
            ->when($stage === 'national', fn ($query) => $query->finallyApproved())
            ->with('user.institution')->join('users as report_teachers', 'report_teachers.id', '=', 'applications.user_id')
            ->select('applications.*')->orderBy('report_teachers.name')->orderBy('applications.id')->get();
        $scope = $user->isSuperAdmin() ? 'Todas las instituciones' : ($user->hasRole('Admin Nacional')
            ? 'Solicitudes nacionales asignadas a '.$user->name : ($user->institution?->name ?? 'Sin institucion asignada'));
        $pdf = (new \App\Services\ApprovedReportPdf($selected, $stage, $scope))->render($applications);
        $filename = 'reporte_etapa_'.$stage.'_convocatoria_'.$selected->id.'.pdf';
        return response($pdf, 200, ['Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"', 'Cache-Control' => 'private, no-store']);
    }

    public function index(Request $request)
    {
        $filters = $request->validate([
            'announcement_id' => ['nullable', 'integer', 'min:1'],
            'local_page' => ['nullable', 'integer', 'min:1'],
            'national_page' => ['nullable', 'integer', 'min:1'],
        ]);
        $announcements = $this->announcements($request)
            ->latest('id')->get(['id', 'name']);
        $selectedId = isset($filters['announcement_id']) ? (int) $filters['announcement_id'] : $announcements->first()?->id;
        if ($selectedId) abort_unless($announcements->contains('id', $selectedId), 404);
        $base = $this->applications($request)->where('announcement_id', $selectedId ?: 0)->approved();
        $list = function ($query, string $page) {
            return $query->with('user.institution')->join('users as report_teachers', 'report_teachers.id', '=', 'applications.user_id')
                ->select('applications.*')->orderBy('report_teachers.name')->orderBy('applications.id')
                ->paginate(15, ['applications.*'], $page)->withQueryString()->through(fn ($application) => [
                    'id' => $application->id, 'name' => $application->user?->name,
                    'email' => $application->user?->email, 'institution' => $application->user?->institution?->name,
                ]);
        };
        return Inertia::render('Reports/ClosedAnnouncements', [
            'announcements' => $announcements, 'selectedAnnouncementId' => $selectedId,
            ...($request->user()->isSuperAdmin() || !$request->user()->hasRole('Admin Nacional')
                ? ['localApproved' => $list(clone $base, 'local_page')] : []),
            ...($request->user()->isSuperAdmin() || $request->user()->hasRole('Admin Nacional')
                ? ['nationalApproved' => $list((clone $base)->finallyApproved(), 'national_page')] : []),
        ]);
    }
}
