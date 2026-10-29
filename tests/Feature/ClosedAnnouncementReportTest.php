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
        $template = new \FPDF('P', 'mm', 'Letter');
        $template->AddPage(); $template->SetFont('Arial', '', 12); $template->Cell(0, 10, 'Institutional letterhead');
        \Illuminate\Support\Facades\Storage::disk('public')->put('templates/template_reporte_membrete.pdf', $template->Output('S'));
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
}
