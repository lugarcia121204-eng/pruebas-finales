<?php

namespace Tests\Feature;

use App\Models\{Announcement, Application, Calendar, CatalogDocument, NationalReview, NationalEvaluation, Recognition, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Storage, Mail};
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SystemRemediationTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_files_are_available_without_exposing_private_or_unpublished_files(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('announcements/public.pdf', '%PDF-1.4 example');
        Storage::disk('public')->put('documents/private.pdf', '%PDF-1.4 private');
        Announcement::create(['name' => 'Public', 'status' => 'activa', 'file_path' => 'announcements/public.pdf']);
        $this->get('/storage/announcements/public.pdf')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('/storage/documents/private.pdf')->assertNotFound();
        $this->get('/storage/../.env')->assertNotFound();
        Storage::disk('public')->put('catalog/example.pdf', '%PDF-1.4 catalog');
        $document = CatalogDocument::create(['name' => 'Template', 'file_path' => 'catalog/example.pdf', 'active' => false]);
        $this->get('/storage/catalog/example.pdf')->assertNotFound();
        $document->update(['active' => true]);
        $this->get('/storage/catalog/example.pdf')->assertOk();
        Storage::disk('public')->delete('catalog/example.pdf');
        $this->get('/storage/catalog/example.pdf')->assertNotFound();
    }

    private function reviewFixture(): array
    {
        Mail::fake();
        Storage::fake('public');
        Storage::fake('local');
        Role::findOrCreate('Admin Nacional', 'web');
        $admin = User::factory()->create(); $admin->assignRole('Admin Nacional');
        $teacher = User::factory()->create(['curp' => 'GAGG041212MPLRRDA7']);
        $announcement = Announcement::create(['name' => 'Test national']);
        $calendar = Calendar::create(['announcement_id' => $announcement->id, 'publication_start' => now()->subDays(10), 'registration_start' => now()->subDays(9), 'registration_end' => now()->subDays(8), 'evaluation_start' => now()->subDays(7), 'evaluation_end' => now()->subDays(2), 'results_start' => now()->subDay(), 'results_end' => now()->addDays(5)]);
        $application = Application::create(['user_id' => $teacher->id, 'announcement_id' => $announcement->id, 'status' => 'approved']);
        $review = NationalReview::create(['application_id' => $application->id, 'administrator_id' => $admin->id, 'assigned_by' => $admin->id, 'status' => 'pending']);
        NationalEvaluation::create(['national_review_id' => $review->id, 'evaluator_id' => User::factory()->create()->id, 'status' => 'completed', 'score' => 10]);
        $recognition = Recognition::create(['user_id' => $teacher->id, 'announcement_id' => $announcement->id, 'type' => 'postulante', 'identifier' => 'DOC-TEST-NATIONAL', 'active' => true]);
        return [$admin, $teacher, $application, $review, $recognition, $calendar];
    }

    public function test_institutional_approval_does_not_publish_final_documents_but_national_approval_does(): void
    {
        [$admin, $teacher, $application, $review, $recognition] = $this->reviewFixture();
        $this->assertFalse(Application::whereKey($application->id)->finallyApproved()->exists());
        $this->get(route('recognition.verify', $recognition->identifier))->assertNotFound();
        $this->get(route('recognitions.public-download', $recognition->identifier))->assertNotFound();
        $this->get(route('recognitions.search', ['folio' => $recognition->identifier]))->assertOk()->assertInertia(fn (Assert $page) => $page->has('recognitions', 0));
        app(\App\Services\FinalRecognitionService::class)->sync($application);
        $this->assertFalse($recognition->fresh()->active);
        $this->actingAs($admin)->post(route('national-review.verdict', $review->id), ['status' => 'approved'])->assertSessionHasNoErrors();
        $this->assertTrue($recognition->fresh()->active);
        $this->assertTrue(Application::whereKey($application->id)->finallyApproved()->exists());
        $this->assertSame('approved', $application->fresh()->finalDecision());
        $this->get(route('recognition.verify', $recognition->identifier))->assertOk();
        $this->get(route('recognitions.search', ['folio' => $recognition->identifier]))->assertOk()->assertInertia(fn (Assert $page) => $page->has('recognitions', 1));
    }

    public function test_acceptance_letter_requires_national_approval_and_results(): void
    {
        [$admin, $teacher, $application, $review, $recognition, $calendar] = $this->reviewFixture();
        Role::findOrCreate('Docente', 'web');
        $teacher->assignRole('Docente');
        $application->update(['identifier' => 'ACE-LEGACY-LOCAL']);
        $this->mock(\App\Services\SignatureService::class, fn ($mock) => $mock->shouldReceive('sign')->andReturn('TEST-SIGNATURE'));
        $url = route('teacher.documents.downloadAcceptance', $application->id);
        $show = route('teacher.applications.show', $application->id);
        $this->actingAs($teacher)->get($url)->assertNotFound();
        $this->get($show)->assertOk()->assertInertia(fn (Assert $page) => $page->where('application.acceptance_available', false));
        $this->get(route('recognition.verify', $application->identifier))->assertNotFound();
        $pdf = new \FPDF(); $pdf->AddPage(); $pdf->SetFont('Arial', '', 12); $pdf->Cell(30, 10, 'Acceptance');
        Storage::disk('public')->put('templates/acceptance.pdf', $pdf->Output('S'));
        \App\Models\Template::create(['name' => 'Acceptance', 'type' => 'acceptance', 'file_path' => 'templates/acceptance.pdf', 'file_name' => 'acceptance.pdf', 'is_active' => true]);
        $review->update(['status' => 'approved']);
        $this->get($show)->assertOk()->assertInertia(fn (Assert $page) => $page->where('application.acceptance_available', true));
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get(route('recognition.verify', $application->identifier))->assertOk();
        $calendar->update(['results_start' => now()->addDays(2)]);
        $this->get($show)->assertOk()->assertInertia(fn (Assert $page) => $page->where('application.acceptance_available', false));
        $this->get($url)->assertForbidden();
        $this->get(route('recognition.verify', $application->identifier))->assertNotFound();
        $calendar->update(['results_start' => now()->subDay()]);
        $review->update(['status' => 'rejected']);
        $this->get($url)->assertNotFound();
        $this->get($show)->assertOk()->assertInertia(fn (Assert $page) => $page->where('application.acceptance_available', false));
        $this->get(route('recognition.verify', $application->identifier))->assertNotFound();
    }

    public function test_acceptance_snapshot_cannot_be_created_with_only_local_approval(): void
    {
        [, , $application] = $this->reviewFixture();
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(\App\Services\PdfGenerationService::class)->freezeAcceptanceLetter($application);
    }

    public function test_approval_creates_teacher_recognition_and_national_list_is_scoped(): void
    {
        [$admin, $teacher, $application, $review, $recognition] = $this->reviewFixture();
        $recognition->delete();
        $this->actingAs($admin)->get(route('national-review.recognitions.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('recognitions.data', 0));
        $this->post(route('national-review.verdict', $review->id), ['status' => 'approved'])->assertSessionHasNoErrors();
        $created = Recognition::where('user_id', $teacher->id)->where('announcement_id', $application->announcement_id)->where('type', 'postulante')->where('stage', 'national')->firstOrFail();
        $this->assertTrue((bool) $created->active);
        $this->get(route('national-review.recognitions.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('recognitions.data', 1)->where('recognitions.data.0.id', $created->id));
        $other = User::factory()->create(); $other->assignRole('Admin Nacional');
        $this->actingAs($other)->get(route('national-review.recognitions.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('recognitions.data', 0));
        $this->get(route('national-review.recognitions.download', $created->id))->assertNotFound();
        $this->actingAs($admin)->get(route('security.users.index'))->assertForbidden();
    }

    public function test_national_admin_can_disable_and_reactivate_only_own_national_certificate(): void
    {
        [$admin, $teacher, $application, $review, $recognition] = $this->reviewFixture();
        $review->update(['status' => 'approved']);
        $url = route('national-review.recognitions.toggle', $recognition->id);
        $other = User::factory()->create(); $other->assignRole('Admin Nacional');
        $this->actingAs($other)->patch($url, ['active' => false])->assertNotFound();
        $this->actingAs($admin)->patch($url, ['active' => false])->assertSessionHasNoErrors();
        $this->assertFalse($recognition->fresh()->active);
        $this->get(route('national-review.recognitions.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('recognitions.data', 1)->where('recognitions.data.0.active', false));
        $this->get(route('recognition.verify', $recognition->identifier))->assertNotFound();
        $this->get(route('recognitions.public-download', $recognition->identifier))->assertNotFound();
        app(\App\Services\FinalRecognitionService::class)->sync($application);
        $this->assertFalse($recognition->fresh()->active);
        $local = Recognition::create(['user_id' => $teacher->id, 'announcement_id' => $application->announcement_id, 'type' => 'postulante', 'stage' => 'local', 'active' => false]);
        $this->patch(route('national-review.recognitions.toggle', $local->id), ['active' => false])->assertNotFound();
        $this->patch($url, ['active' => true])->assertSessionHasNoErrors();
        $this->assertTrue($recognition->fresh()->active);
        $this->get(route('recognition.verify', $recognition->identifier))->assertOk();
        $review->update(['status' => 'pending']);
        $this->patch($url, ['active' => true])->assertNotFound();
    }

    public function test_only_national_approval_issues_one_certificate_and_rejection_revokes_it(): void
    {
        [$admin, $teacher, $application, $review, $premature] = $this->reviewFixture();
        $premature->delete();
        $this->mock(\App\Services\SignatureService::class, function ($mock) {
            $mock->shouldReceive('sign')->andReturn('TEST-SIGNATURE');
        });
        $pdf = new \FPDF(); $pdf->AddPage(); $pdf->SetFont('Arial', '', 12); $pdf->Cell(30, 10, 'Template');
        Storage::disk('public')->put('templates/test.pdf', $pdf->Output('S'));
        \App\Models\Template::create(['name' => 'Recognition', 'type' => 'recognition', 'file_path' => 'templates/test.pdf', 'file_name' => 'test.pdf', 'is_active' => true]);
        $service = app(\App\Services\FinalRecognitionService::class);
        $service->sync($application);
        $this->assertDatabaseCount('recognitions', 0);
        Mail::assertNothingQueued();
        $review->update(['status' => 'approved']);
        $service->sync($application);
        $national = Recognition::where('stage', 'national')->firstOrFail();
        $this->assertStringContainsString('-NACIONAL-', $national->identifier);
        $service->sync($application);
        $this->assertDatabaseCount('recognitions', 1);
        foreach ([$national] as $certificate) {
            $response = app(\App\Services\PdfGenerationService::class)->generateTeacherRecognitionPdf($certificate, $teacher);
            $this->assertStringStartsWith('%PDF-', $response->getContent());
            $stored = $certificate->fresh()->file_path;
            $this->assertStringStartsWith('reconocimientos/', $stored);
            $this->assertStringContainsString('/GAGG041212MPLRRDA7/solicitud-'.$application->id.'/', $stored);
            $this->assertSame($response->getContent(), Storage::disk('local')->get($stored));
            $this->assertSame($response->getContent(), app(\App\Services\PdfGenerationService::class)->generateTeacherRecognitionPdf($certificate->fresh(), $teacher)->getContent());
            $this->assertSame('inline; filename="'.$certificate->identifier.'.pdf"', $response->headers->get('Content-Disposition'));
        }
        $notifier = app(\App\Services\NotificationService::class);
        foreach ([$national] as $certificate) {
            $notifier->notifyTeacherRecognition($certificate);
            $notifier->notifyTeacherRecognition($certificate);
        }
        Mail::assertQueued(\App\Mail\RecognitionAvailable::class, 1);
        $this->assertDatabaseCount('notifications', 1);
        foreach ([$national] as $certificate) {
            Mail::assertQueued(\App\Mail\RecognitionAvailable::class, function ($mail) use ($certificate) {
                if ($mail->recognitionId !== $certificate->id || !$mail->hasTo($certificate->user->email)) return false;
                $attachment = $mail->attachments()[0];
                $this->assertSame($certificate->identifier.'.pdf', $attachment->as);
                $this->assertSame('application/pdf', $attachment->mime);
                $data = $attachment->attachWith(fn () => null, fn ($resolve) => $resolve());
                $this->assertStringStartsWith('%PDF-', $data);
                return true;
            });
        }
        $review->update(['status' => 'rejected']);
        $service->sync($application);
        $this->assertFalse($national->fresh()->active);
        $this->get(route('recognition.verify', $national->identifier))->assertNotFound();
    }

    public function test_legacy_local_certificate_is_unavailable_and_deactivated_on_sync(): void
    {
        [$admin, $teacher, $application, $review] = $this->reviewFixture();
        $local = Recognition::create(['user_id' => $teacher->id, 'announcement_id' => $application->announcement_id,
            'type' => 'postulante', 'stage' => 'local', 'active' => true, 'identifier' => 'LEGACY-LOCAL']);
        $this->assertFalse(Recognition::whereKey($local->id)->publishable()->exists());
        $this->get(route('recognition.verify', $local->identifier))->assertNotFound();
        $this->get(route('recognitions.public-download', $local->identifier))->assertNotFound();
        app(\App\Services\FinalRecognitionService::class)->sync($application);
        $this->assertFalse($local->fresh()->active);
    }

    public function test_pdf_archiving_preserves_national_certificate_before_results_without_sending_mail(): void
    {
        [$admin, $teacher, $application, $review, $recognition, $calendar] = $this->reviewFixture();
        $this->mock(\App\Services\SignatureService::class, fn ($mock) => $mock->shouldReceive('sign')->andReturn('TEST-SIGNATURE'));
        $pdf = new \FPDF(); $pdf->AddPage(); $pdf->SetFont('Arial', '', 12); $pdf->Cell(30, 10, 'Template');
        Storage::disk('public')->put('templates/archive.pdf', $pdf->Output('S'));
        \App\Models\Template::create(['name' => 'Recognition', 'type' => 'recognition', 'file_path' => 'templates/archive.pdf', 'file_name' => 'archive.pdf', 'is_active' => true]);
        $review->update(['status' => 'approved']);
        $calendar->update(['results_start' => now()->addDays(2)]);
        app(\App\Services\FinalRecognitionService::class)->sync($application);
        $this->artisan('recognitions:archive-pdfs')->assertSuccessful();
        $records = Recognition::where('type', 'postulante')->get();
        $this->assertCount(1, $records);
        foreach ($records as $record) {
            $this->assertStringEndsWith('/'.$record->identifier.'.pdf', $record->file_path);
            $this->assertStringStartsWith('%PDF-', Storage::disk('local')->get($record->file_path));
            Storage::disk('public')->assertMissing($record->file_path);
            $this->get('/storage/'.$record->file_path)->assertNotFound();
            $this->get(route('recognitions.public-download', $record->identifier))->assertNotFound();
        }
        $this->artisan('recognitions:archive-pdfs')->assertSuccessful();
        $this->assertCount(1, Storage::disk('local')->allFiles('reconocimientos'));
        Mail::assertNothingQueued();
    }

    public function test_national_rejection_disables_recognition_and_results_calendar_still_applies(): void
    {
        [$admin, $teacher, $application, $review, $recognition, $calendar] = $this->reviewFixture();
        $review->update(['status' => 'approved']);
        $calendar->update(['results_start' => now()->addDays(2)]);
        $this->assertFalse(Recognition::whereKey($recognition->id)->publishable()->exists());
        $review->update(['status' => 'pending']);
        $this->actingAs($admin)->post(route('national-review.verdict', $review->id), ['status' => 'rejected', 'comment' => 'Does not satisfy national requirements.'])->assertSessionHasNoErrors();
        $this->assertFalse($recognition->fresh()->active);
        $this->assertSame('rejected', $application->fresh()->finalDecision());
        $this->assertSame('approved', $application->fresh()->status);
        $this->get(route('recognition.verify', $recognition->identifier))->assertNotFound();
    }

    public function test_national_admin_can_update_profile_without_institution_or_academic_fields(): void
    {
        Role::findOrCreate('Admin Nacional', 'web');
        $user = User::factory()->create(['institution_id' => null]); $user->assignRole('Admin Nacional');
        $this->actingAs($user)->patch(route('profile.update'), ['name' => 'National administrator', 'email' => $user->email, 'institution_id' => null])
            ->assertSessionHasNoErrors()->assertRedirect(route('profile.edit'));
        $this->assertNull($user->fresh()->institution_id);
        $this->assertSame('National administrator', $user->fresh()->name);
    }

    public function test_verification_code_only_verifies_the_account_pending_in_the_session(): void
    {
        $user = User::factory()->unverified()->create(['email_verification_code' => '123456', 'email_verification_code_expires_at' => now()->addHour()]);
        $other = User::factory()->unverified()->create(['email_verification_code' => '654321', 'email_verification_code_expires_at' => now()->addHour()]);
        $this->actingAs($user)->post(route('verification.verify.code'), ['code' => '654321'])->assertSessionHasErrors('code');
        $this->assertNull($other->fresh()->email_verified_at);
        $this->post(route('verification.verify.code'), ['code' => '123456'])->assertSessionHasNoErrors();
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertNull($other->fresh()->email_verified_at);
    }
}
