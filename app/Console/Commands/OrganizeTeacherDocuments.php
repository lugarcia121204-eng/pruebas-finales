<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Services\TeacherDocumentStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{DB, Storage};

class OrganizeTeacherDocuments extends Command
{
    protected $signature = 'documents:organize {--apply : Reorganizar archivos con respaldo previo}';
    protected $description = 'Organiza los expedientes por convocatoria y anio, ID de institucion y CURP del docente';

    public function handle(TeacherDocumentStorage $organizer): int
    {
        $disk = Storage::disk('public');
        $root = realpath($disk->path('documents'));
        $plans = [];
        foreach (Document::withTrashed()->with(['application' => fn ($query) => $query->withTrashed()->with('user.institution')])->get() as $document) {
            if (!$document->application) throw new \RuntimeException('Documento sin solicitud: '.$document->id);
            $directory = $organizer->directory($document->application);
            if (str_starts_with($document->file_path, $directory.'/')) continue;
            $source = realpath($disk->path($document->file_path));
            if (!$root || !$source || !is_file($source) || !str_starts_with(strtolower($source), strtolower($root.DIRECTORY_SEPARATOR))) {
                throw new \RuntimeException('Archivo no encontrado o fuera de documents: '.$document->id);
            }
            $target = $directory.'/'.$document->id.'-'.basename($source);
            $ancestor = dirname($disk->path($target));
            while (!is_dir($ancestor)) $ancestor = dirname($ancestor);
            $resolved = realpath($ancestor);
            if ($resolved !== $root && !str_starts_with(strtolower($resolved), strtolower($root.DIRECTORY_SEPARATOR))) {
                throw new \RuntimeException('El destino no pertenece al directorio de documentos.');
            }
            $hash = hash_file('sha256', $source);
            if ($disk->exists($target) && hash_file('sha256', $disk->path($target)) !== $hash) {
                throw new \RuntimeException('Existe un archivo distinto en el destino: '.$document->id);
            }
            $plans[] = ['id' => $document->id, 'old' => $document->file_path, 'new' => $target, 'sha256' => $hash];
        }
        $this->info('Documentos por reorganizar: '.count($plans));
        if (!$this->option('apply') || !$plans) return self::SUCCESS;
        $backup = 'backups/organizacion_documentos_'.now()->format('Ymd_His').'_'.bin2hex(random_bytes(3));
        $private = Storage::disk('local');
        if (!$private->put($backup.'/manifest.json', json_encode($plans, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR))) {
            throw new \RuntimeException('No se pudo guardar el respaldo de rutas.');
        }
        foreach ($plans as $plan) {
            $contents = $disk->get($plan['old']);
            if (!$private->put($backup.'/files/'.$plan['id'].'.pdf', $contents)
                || hash('sha256', $private->get($backup.'/files/'.$plan['id'].'.pdf')) !== $plan['sha256']) {
                throw new \RuntimeException('No se pudo respaldar el documento: '.$plan['id']);
            }
            if (!$disk->exists($plan['new']) && !$disk->copy($plan['old'], $plan['new'])) {
                throw new \RuntimeException('No se pudo copiar el documento: '.$plan['id']);
            }
            if (hash_file('sha256', $disk->path($plan['new'])) !== $plan['sha256']) {
                throw new \RuntimeException('La copia no coincide con el original: '.$plan['id']);
            }
        }
        DB::transaction(function () use ($plans) {
            foreach ($plans as $plan) {
                $document = Document::withTrashed()->lockForUpdate()->findOrFail($plan['id']);
                if ($document->file_path !== $plan['old']) throw new \RuntimeException('El documento cambio durante la reorganizacion.');
                $document->update(['file_path' => $plan['new']]);
            }
        });
        foreach ($plans as $plan) {
            if (!Document::withTrashed()->where('file_path', $plan['old'])->exists() && !$disk->delete($plan['old'])) {
                $this->warn('El original respaldado no se pudo retirar: '.$plan['id']);
            }
        }
        $this->info('Reorganizados: '.count($plans).'. Respaldo privado: '.$backup);
        return self::SUCCESS;
    }
}
