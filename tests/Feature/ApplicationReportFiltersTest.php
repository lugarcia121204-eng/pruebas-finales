<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Application;
use App\Models\Institution;
use App\Models\NationalReview;
use App\Models\PriorityArea;
use App\Models\SubArea;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ApplicationReportFiltersTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        foreach (['Super Admin', 'Admin', 'Admin Nacional'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        foreach (['requests.index', 'requests.show'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $state = DB::table('states')->insertGetId(['name' => 'Puebla', 'abbreviation' => 'PU']);
        $institution = Institution::create(['name' => 'Campus A', 'state_id' => $state]);
        $other = Institution::create(['name' => 'Campus B', 'state_id' => $state]);
        $area = PriorityArea::create(['name' => 'Ingeniería']);
        $sub = SubArea::create(['name' => 'Sistemas', 'priority_area_id' => $area->id]);
        $otherSub = SubArea::create(['name' => 'Electrónica', 'priority_area_id' => $area->id]);
        $otherArea = PriorityArea::create(['name' => 'Ciencias']);
        $foreignSub = SubArea::create(['name' => 'Física', 'priority_area_id' => $otherArea->id]);
        $teacher = User::factory()->create(['institution_id' => $institution->id, 'priority_area_id' => $area->id, 'sub_area_id' => $sub->id]);
        $otherTeacher = User::factory()->create(['institution_id' => $other->id, 'priority_area_id' => $area->id, 'sub_area_id' => $sub->id]);
        $announcement = Announcement::create(['name' => 'Prueba de reportes']);

        return compact('institution', 'other', 'area', 'sub', 'otherSub', 'otherArea', 'foreignSub', 'teacher', 'otherTeacher', 'announcement');
    }

    private function application(array $fixture, string $status, string $date, ?User $teacher = null): Application
    {
        $application = Application::create(['user_id' => ($teacher ?? $fixture['teacher'])->id, 'announcement_id' => $fixture['announcement']->id, 'status' => $status]);
        DB::table('applications')->where('id', $application->id)->update(['created_at' => $date]);

        return $application;
    }

    public function test_screen_and_excel_share_combined_filters_with_inclusive_dates(): void
    {
        $f = $this->fixture();
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');
        $first = $this->application($f, 'approved', '2026-10-01 00:00:00');
        $last = $this->application($f, 'approved', '2026-10-31 23:59:59');
        $this->application($f, 'approved', '2026-11-01 00:00:00');
        $this->application($f, 'rejected', '2026-10-15 12:00:00');
        $this->application($f, 'approved', '2026-10-15 12:00:00', $f['otherTeacher']);
        $different = User::factory()->create(['institution_id' => $f['institution']->id, 'priority_area_id' => $f['area']->id, 'sub_area_id' => $f['otherSub']->id]);
        $this->application($f, 'approved', '2026-10-15 12:00:00', $different);
        $filters = ['date_from' => '2026-10-01', 'date_to' => '2026-10-31', 'priority_area_id' => $f['area']->id, 'sub_area_id' => $f['sub']->id, 'institution_id' => $f['institution']->id, 'status' => 'approved'];
        $this->actingAs($admin)->get(route('superadmin.control-applications', $filters))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('institutions.data', 1)->where('institutions.data.0.approved_count', 2)->where('institutions.data.0.rejected_count', 0)->where('institutions.data.0.filtered_applications_count', 2)
            ->where('filters.status', 'approved')->has('priorityAreas', 2)->has('subAreas', 3));
        Excel::fake();
        $this->get(route('superadmin.control-applications.export', $filters))->assertOk();
        Excel::assertDownloaded('control_solicitudes_'.now()->format('Y-m-d').'.xlsx', function ($export) use ($first, $last) {
            $rows = $export->collection();

            return $rows->pluck('id')->sort()->values()->all() === [$first->id, $last->id]
                && $rows->first()->area_name === 'Ingeniería' && $rows->first()->sub_area_name === 'Sistemas';
        });
    }

    public function test_pending_only_institution_is_visible_and_exported_as_pending(): void
    {
        $f = $this->fixture();
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');
        $pending = $this->application($f, 'pending', '2026-10-15 12:00:00');
        $this->actingAs($admin)->get(route('superadmin.control-applications', ['status' => 'pending']))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('institutions.data', 1)->where('institutions.data.0.filtered_applications_count', 1)->where('institutions.data.0.approved_count', 0));
        Excel::fake();
        $this->get(route('superadmin.control-applications.export', ['status' => 'pending']))->assertOk();
        Excel::assertDownloaded('control_solicitudes_'.now()->format('Y-m-d').'.xlsx', fn ($export) => $export->collection()->pluck('id')->all() === [$pending->id]);
    }

    public function test_invalid_dates_subarea_and_sort_are_rejected_on_screen_and_export(): void
    {
        $f = $this->fixture();
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');
        $invalid = ['date_from' => '2026-10-31', 'date_to' => '2026-10-01', 'priority_area_id' => $f['area']->id, 'sub_area_id' => $f['foreignSub']->id, 'sort_field' => 'password'];
        foreach (['superadmin.control-applications', 'superadmin.control-applications.export'] as $route) {
            $this->actingAs($admin)->get(route($route, $invalid))->assertSessionHasErrors(['date_to', 'sub_area_id', 'sort_field']);
        }
    }

    public function test_local_export_and_details_keep_filters_and_cannot_leak_other_institutions(): void
    {
        $f = $this->fixture();
        $admin = User::factory()->create(['institution_id' => $f['institution']->id]);
        $admin->assignRole('Admin');
        $admin->givePermissionTo(['requests.index', 'requests.show']);
        $own = $this->application($f, 'pending', '2026-10-15 12:00:00');
        $this->application($f, 'pending', '2026-10-15 12:00:00', $f['otherTeacher']);
        $this->application($f, 'pending', '2026-09-01 12:00:00');
        $filters = ['status' => 'pending', 'date_from' => '2026-10-01', 'priority_area_id' => $f['area']->id];
        $this->actingAs($admin)->get(route('applications.control.index', $filters))->assertOk()->assertInertia(fn (Assert $page) => $page->has('institutions.data', 1)->where('institutions.data.0.id', $f['institution']->id)->where('institutions.data.0.filtered_applications_count', 1));
        $this->get(route('applications.control.show', ['id' => $f['institution']->id] + $filters))->assertOk()->assertInertia(fn (Assert $page) => $page->has('applications.data', 1)->where('applications.data.0.id', $own->id));
        Excel::fake();
        $this->get(route('applications.control.export', $filters))->assertOk();
        Excel::assertDownloaded('solicitudes_institucion.xlsx', fn ($export) => $export->collection()->pluck('id')->all() === [$own->id]);
        $this->get(route('applications.control.export', $filters + ['institution_id' => $f['other']->id]))->assertOk();
        Excel::assertDownloaded('solicitudes_institucion.xlsx', fn ($export) => $export->collection()->isEmpty());
    }

    public function test_national_report_filters_national_status_and_only_own_assignments(): void
    {
        $f = $this->fixture();
        $admin = User::factory()->create();
        $admin->assignRole('Admin Nacional');
        $other = User::factory()->create();
        $other->assignRole('Admin Nacional');
        foreach ([[$admin, 'pending', '2026-10-31 23:59:59'], [$admin, 'approved', '2026-10-15 12:00:00'], [$other, 'pending', '2026-10-15 12:00:00'], [$admin, 'pending', '2026-11-01 00:00:00']] as [$owner, $status, $date]) {
            $application = $this->application($f, 'approved', $date);
            NationalReview::create(['application_id' => $application->id, 'administrator_id' => $owner->id, 'assigned_by' => $owner->id, 'status' => $status]);
        }
        $filters = ['status' => 'pending', 'date_from' => '2026-10-01', 'date_to' => '2026-10-31', 'priority_area_id' => $f['area']->id, 'sub_area_id' => $f['sub']->id];
        $this->actingAs($admin)->get(route('national-review.dashboard', $filters))->assertOk()->assertInertia(fn (Assert $page) => $page->has('institutions.data', 1)->where('institutions.data.0.filtered_applications_count', 1)->where('institutions.data.0.approved_count', 0));
        Excel::fake();
        $this->get(route('national-review.dashboard.export', $filters))->assertOk();
        Excel::assertDownloaded('resumen_etapa_nacional.xlsx', fn ($export) => $export->collection()->count() === 1 && $export->collection()->first()[4] === 1 && $export->collection()->first()[2] === 0);
    }

    public function test_empty_results_and_deleted_institutions_match_the_export(): void
    {
        $f = $this->fixture();
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');
        $this->application($f, 'approved', '2026-10-15 12:00:00');
        $f['institution']->delete();
        $this->actingAs($admin)->get(route('superadmin.control-applications'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('institutions.data', 0));
        Excel::fake();
        $this->get(route('superadmin.control-applications.export'))->assertOk();
        Excel::assertDownloaded('control_solicitudes_'.now()->format('Y-m-d').'.xlsx', fn ($export) => $export->collection()->isEmpty());
    }

    public function test_local_export_requires_the_request_control_permission(): void
    {
        $this->fixture();
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin)->get(route('applications.control.export'))->assertForbidden();
    }
}
