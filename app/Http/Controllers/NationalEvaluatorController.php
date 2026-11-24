<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Models\{NationalEvaluation, User};
use Illuminate\Http\Request;
use Inertia\Inertia;

class NationalEvaluatorController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'rows' => ['nullable', 'integer', 'in:5,10,25,50'],
            'sort_field' => ['nullable', 'in:name,email'],
            'sort_direction' => ['nullable', 'in:asc,desc'],
        ]);
        $assignedEvaluators = NationalEvaluation::select('evaluator_id')->whereHas('review', fn ($review) => $review
            ->where('administrator_id', $request->user()->id)
            ->whereHas('application', fn ($application) => $application->approved()->whereHas('announcement')));
        $users = User::role('Evaluador')->whereIn('users.id', $assignedEvaluators)
            ->with(['roles', 'institution'])
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(fn ($match) => $match
                ->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")))
            ->orderBy($filters['sort_field'] ?? 'name', $filters['sort_direction'] ?? 'asc')->orderBy('id')
            ->paginate($filters['rows'] ?? 10)->withQueryString();

        return Inertia::render('Admin/Security/Evaluators/Index', [
            'users' => UserResource::collection($users),
            'filters' => $filters,
            'registrationLink' => route('register.evaluator'),
            'indexRoute' => 'national-review.evaluators.index',
            'canDelete' => false,
            'listDescription' => 'Evaluadores asignados a tus solicitudes nacionales. Comparte el enlace para invitar a nuevos evaluadores a registrarse.',
        ]);
    }
}
