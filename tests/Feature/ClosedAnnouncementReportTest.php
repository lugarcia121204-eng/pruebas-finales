<?php

namespace Tests\Feature;

use App\Models\{Announcement, Application, Institution, NationalReview, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ClosedAnnouncementReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Storage::fake('public');

    }


    public function test_reports_separate_both_approvals_and_enforce_role_and_institution_access(): void
    {
        foreach (['Super Admin', 'Admin', 'Admin Nacional', 'Docente'] as $role) Role::findOrCreate($role, 'web');
        $super = User::factory()->create(); $super->assignRole('Super Admin');
        $national = User::factory()->create(); $national->assignRole('Admin Nacional');
        $otherNational = User::factory()->create(); $otherNational->assignRole('Admin Nacional');
        $state = DB::table('states')->insertGetId(['name' => 'Prueba', 'abbreviation' => 'PR']);
        $a = Institution::create(['name' => 'Sede A', 'state_id' => $state]);
        $b = Institution::create(['name' => 'Sede B', 'state_id' => $state]);
        $admin = User::factory()->create(['institution_id' => $a->id]); $admin->assignRole('Admin');
        $closed = Announcement::create(['name' => 'Cerrada', 'status' => 'cerrada']);
        $open = Announcement::create(['name' => 'Abierta', 'status' => 'activa']);
        $applications = [];
        foreach ([[$a, 'approved', 'approved', $national], [$a, 'approved', 'rejected', $national], [$b, 'approved', 'approved', $otherNational], [$a, 'pending', 'pending', $national], [$a, 'rejected', 'pending', $national]] as [$institution, $localStatus, $nationalStatus, $assigned]) {
            $teacher = User::factory()->create(['institution_id' => $institution->id]);
            $application = Application::create(['user_id' => $teacher->id, 'announcement_id' => $closed->id, 'status' => $localStatus]);
            NationalReview::create(['application_id' => $application->id, 'administrator_id' => $assigned->id, 'assigned_by' => $super->id, 'status' => $nationalStatus]);
            $applications[] = $application;
        }
        Application::create(['user_id' => $admin->id, 'announcement_id' => $open->id, 'status' => 'approved']);
        $url = route('reports.closed-announcements');
        $localPdf = route('reports.closed-announcements.pdf', ['announcement' => $closed->id, 'stage' => 'local']);
        $nationalPdf = route('reports.closed-announcements.pdf', ['announcement' => $closed->id, 'stage' => 'national']);
        foreach ([$localPdf, $nationalPdf] as $pdfUrl) {
            $response = $this->actingAs($super)->get($pdfUrl)->assertOk()->assertHeader('Content-Type', 'application/pdf');
            $this->assertStringStartsWith('%PDF-', $response->getContent());
            $this->assertStringContainsString('attachment;', $response->headers->get('Content-Disposition'));
        }
        $this->actingAs($admin)->get($localPdf)->assertOk();
        $this->get($nationalPdf)->assertForbidden();
        $this->actingAs($national)->get($nationalPdf)->assertOk();
        $this->get($localPdf)->assertForbidden();
        $this->actingAs($super)->get(route('reports.closed-announcements.pdf', ['announcement' => $open->id, 'stage' => 'local']))->assertNotFound();

        $this->actingAs($super)->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('announcements', 1)->where('selectedAnnouncementId', $closed->id)->where('localApproved.total', 3)->where('nationalApproved.total', 2));
        $this->get($url.'?announcement_id='.$open->id)->assertNotFound();
        $this->actingAs($admin)->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->where('localApproved.total', 2)->missing('nationalApproved'));
        $this->get($url.'?national_page=1')->assertOk()->assertInertia(fn (Assert $page) => $page->missing('nationalApproved'));
        $this->actingAs($national)->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->missing('localApproved')->where('nationalApproved.total', 1));
        $this->get($url.'?local_page=1')->assertOk()->assertInertia(fn (Assert $page) => $page->missing('localApproved'));
        $this->actingAs($otherNational)->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->missing('localApproved')->where('nationalApproved.total', 1));
        $admin->update(['institution_id' => null]);
        $this->actingAs($admin)->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->has('announcements', 0)->where('localApproved.total', 0)->missing('nationalApproved'));
        $this->get($url.'?announcement_id='.$closed->id)->assertNotFound();
        $this->get($localPdf)->assertNotFound();
        $teacher = User::factory()->create(); $teacher->assignRole('Docente');
        $this->actingAs($teacher)->get($url)->assertForbidden();
        $this->get($localPdf)->assertForbidden();
        $this->get($nationalPdf)->assertForbidden();
    }

    public function test_report_appears_when_announcement_is_closed_and_keeps_older_closed_announcements(): void
    {
        Role::findOrCreate('Super Admin', 'web');
        $super = User::factory()->create(); $super->assignRole('Super Admin');
        $announcement = Announcement::create(['name' => 'Prueba', 'status' => 'activa']);
        $teacher = User::factory()->create();
        Application::create(['user_id' => $teacher->id, 'announcement_id' => $announcement->id, 'status' => 'approved']);
        $url = route('reports.closed-announcements');
        $this->actingAs($super)->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->has('announcements', 0));
        $announcement->update(['status' => 'cerrada']);
        $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->has('announcements', 1)->where('localApproved.total', 1)->where('nationalApproved.total', 0));
        Announcement::create(['name' => 'Otra cerrada', 'status' => 'cerrada']);
        $this->get($url.'?announcement_id='.$announcement->id)->assertOk()->assertInertia(fn (Assert $page) => $page->has('announcements', 2)->where('localApproved.total', 1));
    }

    public function test_acta_date_and_time_follow_each_export_in_mexico_city(): void
    {
        $announcement = new Announcement(['name' => 'Prueba']);
        try {
            foreach (['local', 'national'] as $stage) {
                foreach ([
                    ['2026-11-23 05:45:00 UTC', '23:45', '22 de noviembre de 2026'],
                    ['2026-11-24 16:07:00 UTC', '10:07', '24 de noviembre de 2026'],
                ] as [$instant, $time, $date]) {
                    $this->travelTo(\Carbon\Carbon::parse($instant));
                    $pdf = new \App\Services\ApprovedReportPdf($announcement, $stage, 'Prueba');
                    $pdf->SetCompression(false);
                    $content = $pdf->render(collect());
                    $this->assertStringContainsString($time.' horas del', $content);
                    $this->assertStringContainsString($date, $content);
                    $this->assertStringNotContainsString('13:48 horas', $content);
                }
            }
        } finally {
            $this->travelBack();
        }
    }

    public function test_acta_uses_stage_evaluations_without_fabricating_participant_points_or_levels(): void
    {
        $announcement = new Announcement(['name' => 'Prueba']);
        $teacher = new User(['name' => 'Participante actual', 'email' => 'docente@example.test']);
        $teacher->setRelation('institution', new Institution(['name' => 'Instituto actual']));
        $evaluator = new User(['name' => 'Evaluador actual']);
        $local = new \App\Models\Evaluation(['evaluator_id' => 5, 'status' => 'approved', 'score' => 37]);
        $local->setRelation('evaluator', $evaluator);
        $national = new \App\Models\NationalEvaluation(['evaluator_id' => 5, 'status' => 'completed', 'score' => 19]);
        $national->setRelation('evaluator', $evaluator);
        $review = new NationalReview();
        $review->setRelation('evaluations', collect([$national]));
        $application = new Application();
        $application->setRelation('user', $teacher)->setRelation('evaluations', collect([$local]))->setRelation('nationalReview', $review);
        foreach (['local' => '37', 'national' => '19'] as $stage => $score) {
            $pdf = new \App\Services\ApprovedReportPdf($announcement, $stage, 'Todas las instituciones');
            $pdf->SetCompression(false);
            $content = $pdf->render(collect([$application]));
            $this->assertStringContainsString('Participante actual', $content);
            $this->assertStringContainsString('Evaluador actual', $content);
            $this->assertStringContainsString('Instituto actual', $content);
            $this->assertStringContainsString('('.$score.') Tj', $content);
            $this->assertStringNotContainsString('('.($stage === 'local' ? '19' : '37').') Tj', $content);
            $this->assertStringContainsString('/Subtype /Form', $content);
        }
        $pdf = new \App\Services\ApprovedReportPdf($announcement, 'local', 'Todas las instituciones');
        $pdf->SetCompression(false);
        $content = $pdf->render(collect(array_fill(0, 65, $application)));
        $this->assertGreaterThan(2, $pdf->PageNo());
        $this->assertSame(65, substr_count($content, '(Participante actual) Tj'));
        $this->assertSame(65, substr_count($content, '(37) Tj'));
    }
}
