<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Institution;
use App\Models\User;
use App\Models\NationalReview;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NationalStageController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'institution_id' => ['nullable', 'integer', 'exists:institutions,id'],
        ]);

        $applications = Application::approved()
            ->with(['user.institution', 'announcement', 'nationalReview.administrator'])
            ->when($filters['search'] ?? null, function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('applications.id', 'LIKE', "%{$search}%")
                        ->orWhereHas('user', function ($user) use ($search) {
                            $user->where('name', 'LIKE', "%{$search}%")
                                ->orWhereHas('institution', fn ($institution) =>
                                    $institution->where('name', 'LIKE', "%{$search}%"));
                        });
                });
            })
            ->when($filters['institution_id'] ?? null, fn ($query, $id) =>
                $query->whereHas('user', fn ($user) => $user->where('institution_id', $id)))
            ->orderByDesc('updated_at')->orderByDesc('id')
            ->paginate(15)->withQueryString()
            ->through(fn ($application) => [
                'id' => $application->id,
                'teacher' => $application->user?->name ?? 'Usuario no disponible',
                'institution' => $application->user?->institution?->name ?? 'Sin institución asignada',
                'announcement' => $application->announcement?->name ?? 'Convocatoria no disponible',
                'status' => $application->status,
                'national_administrator_id' => $application->nationalReview?->administrator_id,
                'national_administrator' => $application->nationalReview?->administrator?->name,
                'national_status' => $application->nationalReview?->status ?? 'unassigned',
            ]);

        return Inertia::render('SuperAdmin/NationalStage/Index', [
            'applications' => $applications,
            'institutions' => Institution::whereHas('applications', fn ($query) => $query->approved())
                ->orderBy('name')->get(['id', 'name']),
            'filters' => $filters,
            'nationalAdministrators' => User::whereHas('roles', fn ($query) => $query->where('name', 'Admin Nacional'))->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function assign(Request $request, int $id)
    {
        $data = $request->validate(['administrator_id' => ['required', 'integer', 'exists:users,id']]);
        DB::transaction(function () use ($request, $id, $data) {
            $application = Application::approved()->lockForUpdate()->findOrFail($id);
            $administrator = User::role('Admin Nacional')->find($data['administrator_id']);
            if (!$administrator || $administrator->id === $application->user_id) {
                throw ValidationException::withMessages(['administrator_id' => 'Selecciona un Admin Nacional distinto al solicitante.']);
            }
            $review = NationalReview::firstOrNew(['application_id' => $application->id]);
            if ($review->exists && $review->administrator_id === $administrator->id) {
                return;
            }
            if ($review->exists) {
                throw ValidationException::withMessages(['administrator_id' => 'La solicitud ya tiene un Admin Nacional asignado y no se puede cambiar.']);
            }
            $review->fill(['administrator_id' => $administrator->id, 'assigned_by' => $request->user()->id, 'status' => 'pending'])->save();
        });
        return back()->with('success', 'Solicitud asignada a Admin Nacional para su segunda revisión.');
    }

    public function assignMany(Request $request)
    {
        $data = $request->validate([
            'administrator_id' => ['required', 'integer', 'exists:users,id'],
            'application_ids' => ['required', 'array', 'min:1', 'max:500'],
            'application_ids.*' => ['required', 'integer', 'distinct', 'exists:applications,id'],
        ]);

        $administrator = User::role('Admin Nacional')->find($data['administrator_id']);
        if (!$administrator) {
            throw ValidationException::withMessages([
                'administrator_id' => 'Selecciona un administrador nacional válido.',
            ]);
        }

        DB::transaction(function () use ($request, $data, $administrator) {
            $applications = Application::approved()
                ->whereIn('id', $data['application_ids'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($applications->count() !== count($data['application_ids'])) {
                throw ValidationException::withMessages([
                    'application_ids' => 'Una o más solicitudes ya no están aprobadas para la etapa nacional.',
                ]);
            }

            if ($applications->contains('user_id', $administrator->id)) {
                throw ValidationException::withMessages([
                    'administrator_id' => 'El administrador nacional seleccionado no puede ser el solicitante.',
                ]);
            }

            $assignedIds = NationalReview::whereIn('application_id', $data['application_ids'])
                ->lockForUpdate()
                ->pluck('application_id');

            if ($assignedIds->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'application_ids' => 'Una o más solicitudes ya tienen una revisión nacional asignada. Actualiza la lista antes de continuar.',
                ]);
            }

            foreach ($applications as $application) {
                NationalReview::create([
                    'application_id' => $application->id,
                    'administrator_id' => $administrator->id,
                    'assigned_by' => $request->user()->id,
                    'status' => 'pending',
                ]);
            }
        });

        return back()->with('success', 'Las solicitudes seleccionadas se asignaron al administrador nacional.');
    }
}
