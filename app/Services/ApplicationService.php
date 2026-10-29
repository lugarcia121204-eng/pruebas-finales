<?php

namespace App\Services;

use App\Models\Announcement;
use App\Models\Application;
use App\Models\Document;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Exception;

class ApplicationService
{
    /**
     * Valida si un usuario es elegible para aplicar a una convocatoria en específico.
     * Retorna null si es elegible, o un string con el error si no lo es.
     */
    public function checkApplicationEligibility(int $userId, int $announcementId): ?string
    {
        // 1. Validar si ya aplicó a esta misma convocatoria
        $alreadyApplied = Application::where('user_id', $userId)
            ->where('announcement_id', $announcementId)
            ->exists();

        if ($alreadyApplied) {
            return 'Ya has aplicado a esta convocatoria.';
        }

        // 2. Validar si tiene una solicitud "pending" global de cualquier otra
        $hasPending = Application::where('user_id', $userId)
            ->where('status', 'pending')
            ->exists();

        if ($hasPending) {
            return 'Tienes una solicitud pendiente de veredicto. Espera a que sea evaluada.';
        }

        return null;
    }

    /**
     * Valida y crea una nueva solicitud de beca con sus documentos correspondientes.
     *
     * @param int $userId El ID del usuario que solicita.
     * @param int $announcementId El ID de la convocatoria.
     * @param string $positionType El tipo de plaza.
     * @param array|null $uploadedFiles Archivos subidos en la petición ('files').
     * @param array|null $fileTypes Tipos correspondientes a los archivos subidos.
     * @param array|null $reusedDocuments Documentos reusados.
     * @param string $via La vía de solicitud (larga o corta).
     * @return Application
     * @throws Exception Si falta un documento requerido.
     */
    public function createApplication(
        int $userId,
        int $announcementId,
        int $positionTypeId,
        ?array $uploadedFiles,
        ?array $fileTypes,
        ?array $reusedDocuments,
        string $via
    ): Application {
        // Validación manual de los documentos requeridos de la convocatoria.
        // Nota: Idealmente esto podría moverse a un Form Request, pero debido a la lógica mixta (archivos nuevos vs reusados),
        // es necesario verificar qué documentos aplican en total y si cubren los obligatorios.
        $announcement = Announcement::with('catalogDocuments')->findOrFail($announcementId);
        $requiredDocs = $announcement->catalogDocuments()
            ->where('catalog_documents.active', true)
            ->where('is_mandatory', true)
            ->whereIn('announcement_document.via', ['ambas', $via])
            ->pluck('name')
            ->toArray();

        $providedDocs = [];

        // Archivos recién subidos
        if ($uploadedFiles) {
            foreach ($uploadedFiles as $key => $file) {
                if (isset($fileTypes[$key])) {
                    $providedDocs[] = $fileTypes[$key];
                }
            }
        }

        // Archivos reusados
        if ($reusedDocuments) {
            foreach ($reusedDocuments as $name => $docId) {
                $providedDocs[] = $name;
            }
        }

        // Validamos contra los requeridos
        foreach ($requiredDocs as $req) {
            if (!in_array($req, $providedDocs)) {
                throw new Exception("El documento '{$req}' es obligatorio.");
            }
        }

        $storedPaths = [];
        try {
            return DB::transaction(function () use (
            $userId,
            $announcementId,
            $positionTypeId,
            $uploadedFiles,
            $fileTypes,
            $reusedDocuments,
            $via,
            &$storedPaths
        ) {
            $application = Application::create([
                'user_id' => $userId,
                'announcement_id' => $announcementId,
                'status' => 'pending',
                'position_type_id' => $positionTypeId,
                'via' => $via,
            ]);

            $directory = app(TeacherDocumentStorage::class)->directory($application);

            // Guardar nuevos archivos subidos
            if ($uploadedFiles) {
                foreach ($uploadedFiles as $key => $file) {
                    $typeName = $fileTypes[$key] ?? 'Documento';

                    $path = $file->store($directory, 'public');
                    if (!$path) throw new Exception('No se pudo guardar el documento.');
                    $storedPaths[] = $path;

                    Document::create([
                        'application_id' => $application->id,
                        'name' => $typeName,
                        'file_path' => $path,
                        'file_type' => $file->getClientOriginalExtension(),
                    ]);
                }
            }

            // Copiar los documentos reusados al directorio de esta nueva solicitud
            if ($reusedDocuments) {
                foreach ($reusedDocuments as $name => $originalDocId) {
                    // Prevenimos sobreescribir si ya subió una nueva versión con este mismo nombre arriba.
                    $exists = Document::where('application_id', $application->id)
                        ->where('name', $name)
                        ->exists();

                    if (!$exists) {
                        $originalDoc = Document::whereHas('application', fn ($query) => $query->where('user_id', $userId))->findOrFail($originalDocId);
                        if (!Storage::disk('public')->exists($originalDoc->file_path)) {
                            throw new Exception('El documento que deseas reutilizar no se encuentra disponible.');
                        }
                        if ($originalDoc) {
                            $newPath = $directory . '/' . \Illuminate\Support\Str::uuid() . '.pdf';
                            if (!Storage::disk('public')->copy($originalDoc->file_path, $newPath)) {
                                throw new Exception('No se pudo copiar el documento reutilizado.');
                            }

                            $storedPaths[] = $newPath;
                            Document::create([
                                'application_id' => $application->id,
                                'name' => $name,
                                'file_path' => $newPath,
                                'file_type' => $originalDoc->file_type,
                            ]);
                        }
                    }
                }
            }

            return $application;
            });
        } catch (\Throwable $error) {
            Storage::disk('public')->delete($storedPaths);
            throw $error;
        }
    }
}
