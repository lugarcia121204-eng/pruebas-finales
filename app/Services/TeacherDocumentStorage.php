<?php

namespace App\Services;

use App\Models\Application;
use Illuminate\Support\Str;

class TeacherDocumentStorage
{
    public function directory(Application $application): string
    {
        $application->loadMissing(['user', 'announcement' => fn ($query) => $query->withTrashed()]);
        $user = $application->user;
        $institutionFolder = $user?->institution_id ? (string) $user->institution_id : 'sin-institucion';
        $curp = strtoupper(trim($user?->curp ?? ''));
        if (!preg_match('/^[A-Z0-9]{18}$/D', $curp)) {
            throw new \RuntimeException('El docente debe tener una CURP valida para guardar su expediente.');
        }
        $year = ($application->created_at ?? now())->timezone('America/Mexico_City')->format('Y');
        $name = Str::slug($application->announcement?->name ?? 'convocatoria-'.$application->announcement_id) ?: 'convocatoria-'.$application->announcement_id;
        $announcementFolder = substr($name, 0, 80).'-'.$year;
        return 'documents/'.$announcementFolder.'/'.$institutionFolder.'/'.$curp.'/solicitud-'.$application->id;
    }
}
