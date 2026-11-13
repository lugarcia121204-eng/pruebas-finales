<?php

namespace App\Http\Resources;

// use App\Http\Resources\Catalogos\ConvocatoriaResource; // Removed
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApplicationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = auth()->user();
        // El enmascaramiento aplica SOLO cuando la petición llega desde rutas de Docente (teacher.*).
        // Si viene de rutas Admin o Evaluador, siempre se muestra el estado real,
        // independientemente de si el usuario también tiene el rol Docente.
        $isTeacher = $user && $user->hasRole('Docente') && $request->routeIs('teacher.*');
        
        // Determine whether to mask the status and comment for the Teacher
        $status = $this->status;
        $adminComment = $this->admin_comment;
        
        if ($isTeacher && $status === 'approved') {
            $status = $this->nationalReview?->status ?? 'pending';
            $adminComment = $this->nationalReview?->comment;
        }

        $resultsPublished = $this->announcement?->calendar?->finalResultsArePublished() ?? false;

        if ($isTeacher && !$resultsPublished) {
            if ($status !== 'draft') {
                $status = 'pending';
            }
            $adminComment = null;
        }

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'announcement_id' => $this->announcement_id,
            'status' => $status,
            'acceptance_available' => $this->finalDecision() === 'approved'
                && $resultsPublished,
            'via' => $this->via,
            'position_type_id' => $this->position_type_id,
            'position_type_name' => $this->positionType?->name,
            'position_type_code' => $this->positionType?->code,
            'position_full_type' => $this->positionType 
                ? "{$this->positionType->code} - {$this->positionType->name}" 
                : null,
            'admin_comment' => $adminComment,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            
            // Relationships (conditional)
            'user' => new UserResource($this->whenLoaded('user')),
            'announcement' => $this->announcement ? (new \App\Http\Resources\Catalog\AnnouncementResource($this->announcement))->resolve() : null, // Uses AnnouncementResource
            'documents' => $this->when(
                $this->relationLoaded('documents'),
                fn() => \App\Http\Resources\DocumentResource::collection($this->documents)->resolve() // Uses DocumentResource
            ),
            'evaluations' => $this->whenLoaded('evaluations'), // Maybe EvaluationResource later?
            
            // Computeds
            'documents_count' => $this->whenCounted('documents'),
            
            // Computed fields for SuperAdmin Documents view
            'teacher' => $this->user ? [
                'name' => $this->user->name,
                'email' => $this->user->email,
                'department' => $this->user->priorityArea?->name ?? null,
                'sub_area' => $this->user->subArea?->name ?? null,
                'state' => $this->user->institution?->state?->name ?? null, // institucion -> institution
            ] : null,
            'campus' => $this->when(
                $this->relationLoaded('user'),
                fn() => $this->user?->institution?->name ?? 'N/A' // nombre -> name
            ),
        ];
    }
}
