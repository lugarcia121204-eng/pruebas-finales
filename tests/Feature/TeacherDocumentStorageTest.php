<?php

namespace Tests\Feature;

use App\Models\{Announcement, Application, Document, Institution, PositionType, User};
use App\Services\{ApplicationService, TeacherDocumentStorage};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB, Storage};
use Tests\TestCase;

class TeacherDocumentStorageTest extends TestCase
{
    use RefreshDatabase;

    private function teacher(): User
    {
        Storage::fake('public'); Storage::fake('local');
        $state = DB::table('states')->insertGetId(['name' => 'Prueba', 'abbreviation' => 'PR']);
        $institution = Institution::create(['name' => 'Instituto Tecnológico de Prueba', 'state_id' => $state]);
        return User::factory()->create(['institution_id' => $institution->id, 'curp' => 'GAGG041212MPLRRDA7']);
    }

    public function test_uploads_and_reused_documents_use_institution_year_curp_and_separate_applications(): void
    {
        $teacher = $this->teacher();
        $position = PositionType::create(['code' => 'E3505', 'name' => 'Docente']);
        $announcement = Announcement::create(['name' => 'Primera']);
        $service = app(ApplicationService::class);
        $first = $service->createApplication($teacher->id, $announcement->id, $position->id,
            [UploadedFile::fake()->createWithContent('acta.pdf', '%PDF-1.4 test')], ['Acta'], null, 'larga');
        $document = $first->documents()->firstOrFail();
        $directory = app(TeacherDocumentStorage::class)->directory($first);
        $this->assertStringContainsString('/GAGG041212MPLRRDA7/solicitud-'.$first->id, $directory);
        $this->assertStringStartsWith('documents/primera-'.now('America/Mexico_City')->format('Y').'/'.$teacher->institution_id.'/', $directory);
        $this->assertStringStartsWith($directory.'/', $document->file_path);
        $secondAnnouncement = Announcement::create(['name' => 'Segunda']);
        $second = $service->createApplication($teacher->id, $secondAnnouncement->id, $position->id, null, null, ['Acta' => $document->id], 'larga');
        $reused = $second->documents()->firstOrFail();
        $this->assertNotEquals($document->file_path, $reused->file_path);
        $this->assertStringContainsString('/solicitud-'.$second->id.'/', $reused->file_path);
        $this->assertSame(Storage::disk('public')->get($document->file_path), Storage::disk('public')->get($reused->file_path));
        $other = User::factory()->create(['institution_id' => $teacher->institution_id, 'curp' => 'GAGG041212HPLRRDA7']);
        try {
            $service->createApplication($other->id, $secondAnnouncement->id, $position->id,
                [UploadedFile::fake()->createWithContent('otro.pdf', '%PDF-1.4 other')], ['Otro'], ['Acta' => $document->id], 'larga');
            $this->fail('No debe permitir reutilizar documentos ajenos.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            $this->assertDatabaseCount('applications', 2);
            $this->assertCount(2, Storage::disk('public')->allFiles('documents'));
        }
    }

    public function test_existing_files_are_backed_up_relocated_without_content_changes_and_command_is_idempotent(): void
    {
        $teacher = $this->teacher();
        $announcement = Announcement::create(['name' => 'Anterior']);
        $application = Application::create(['user_id' => $teacher->id, 'announcement_id' => $announcement->id, 'status' => 'approved']);
        $application->forceFill(['created_at' => '2024-06-10 12:00:00'])->save();
        Storage::disk('public')->put('documents/'.$application->id.'/original.pdf', '%PDF-1.4 original');
        $document = Document::create(['application_id' => $application->id, 'name' => 'Acta', 'file_path' => 'documents/'.$application->id.'/original.pdf', 'file_type' => 'pdf']);
        $oldPath = $document->file_path;
        $this->artisan('documents:organize')->assertSuccessful();
        $this->assertSame($oldPath, $document->fresh()->file_path);
        $this->artisan('documents:organize', ['--apply' => true])->assertSuccessful();
        $newPath = $document->fresh()->file_path;
        $this->assertStringStartsWith('documents/anterior-2024/'.$teacher->institution_id.'/GAGG041212MPLRRDA7/solicitud-', $newPath);
        $this->assertSame('%PDF-1.4 original', Storage::disk('public')->get($newPath));
        Storage::disk('public')->assertMissing($oldPath);
        $backup = collect(Storage::disk('local')->allFiles('backups'));
        $this->assertCount(2, $backup);
        $this->assertSame('%PDF-1.4 original', Storage::disk('local')->get($backup->first(fn ($path) => str_ends_with($path, '.pdf'))));
        $this->artisan('documents:organize', ['--apply' => true])->assertSuccessful();
        $this->assertCount(2, Storage::disk('local')->allFiles('backups'));
        $this->get('/storage/'.$newPath)->assertNotFound();
    }
}
