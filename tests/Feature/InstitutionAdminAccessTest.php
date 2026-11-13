<?php
namespace Tests\Feature;

use Tests\TestCase;
use App\Models\{User, Institution, Application, Announcement, Calendar, Evaluation, Document};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Mail};
use Spatie\Permission\Models\Role;
use Inertia\Testing\AssertableInertia as Assert;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\Mailer\Exception\TransportException;

class InstitutionAdminAccessTest extends TestCase
{
    use RefreshDatabase;
    private function fixture(): array
    {
        Mail::fake();
        foreach (['Admin','Evaluador','Super Admin'] as $role) Role::findOrCreate($role, 'web');
        $state=DB::table('states')->insertGetId(['name'=>'Prueba','abbreviation'=>'PR']);
        $a=Institution::create(['name'=>'Institución A','state_id'=>$state]);
        $b=Institution::create(['name'=>'Institución B','state_id'=>$state]);
        $admin=User::factory()->create(['institution_id'=>$a->id]); $admin->assignRole('Admin');
        $announcement=Announcement::create(['name'=>'Convocatoria']);
        Calendar::create(['announcement_id'=>$announcement->id,'publication_start'=>now()->subDays(5),'registration_start'=>now()->subDays(4),'registration_end'=>now()->subDays(2),'evaluation_start'=>now()->subDay(),'evaluation_end'=>now()->addDays(3),'results_start'=>now()->addDays(4),'results_end'=>now()->addDays(5)]);
        $apps=[];$evaluators=[];$documents=[];
        foreach ([$a,$b] as $institution) {
            $teacher=User::factory()->create(['institution_id'=>$institution->id]);
            $apps[]= $app=Application::create(['user_id'=>$teacher->id,'announcement_id'=>$announcement->id,'status'=>'approved']);
            $evaluators[]= $evaluator=User::factory()->create(['institution_id'=>$institution->id]); $evaluator->assignRole('Evaluador');
            Evaluation::create(['application_id'=>$app->id,'evaluator_id'=>$evaluator->id,'status'=>'approved','score'=>10]);
            $documents[]=Document::create(['application_id'=>$app->id,'name'=>'Evidencia','file_path'=>'no-debe-abrirse.pdf','file_type'=>'pdf']);
        }
        return [$admin,$a,$b,$apps,$evaluators,$documents];
    }

    public function test_lists_statistics_reports_and_identity_are_limited_to_own_institution(): void
    {
        [$admin,$a,$b,$apps,$evaluators]=$this->fixture();
        $this->actingAs($admin);
        $this->get(route('admin.applications.index'))->assertOk()->assertInertia(fn(Assert $p)=>$p
            ->has('applications.data',1)->where('applications.data.0.id',$apps[0]->id)
            ->has('evaluators',1)->where('evaluators.0.id',$evaluators[0]->id)
            ->where('auth.adminInstitution.name',$a->name));
        $this->get(route('admin.dashboard'))->assertOk()->assertInertia(fn(Assert $p)=>$p
            ->where('stats.total',1)->where('stats.approved',1)->has('topInstitutions.data',1)->has('allInstitutions',1));
        $this->get(route('admin.evaluators.index'))->assertOk()->assertInertia(fn(Assert $p)=>$p->has('users.data',1)->where('users.data.0.id',$evaluators[0]->id));
        $this->get(route('admin.recognitions.index'))->assertOk()->assertInertia(fn(Assert $p)=>$p->has('recognitions.data',1)->where('recognitions.data.0.evaluator_id',$evaluators[0]->id));
        $this->get(route('admin.applications.index',['search'=>$apps[1]->user->email]))->assertOk()->assertInertia(fn(Assert $p)=>$p->has('applications.data',0));
        Excel::fake();
        $this->get(route('admin.dashboard.export'))->assertOk();
        Excel::assertDownloaded('solicitudes_admin_'.now()->format('Y-m-d').'.xlsx',fn($export)=>$export->collection()->pluck('id')->all()===[$apps[0]->id]);
        $this->get(route('admin.dashboard',['institution_id'=>$b->id]))->assertOk()->assertInertia(fn(Assert $p)=>$p->has('institutions.data',0));
    }

    public function test_cross_institution_links_assignments_files_verdicts_and_recognitions_are_denied(): void
    {
        [$admin,$a,$b,$apps,$evaluators,$documents]=$this->fixture();
        $this->actingAs($admin);
        $this->get(route('admin.applications.show',$apps[0]->id))->assertOk();
        $this->get(route('admin.applications.show',$apps[1]->id))->assertNotFound();
        $this->get(route('admin.applications.assign_view',$apps[1]->id))->assertNotFound();
        $this->get(route('catalog.admin.documents.stream',$documents[1]))->assertNotFound();
        $this->get(route('catalog.admin.documents.download',$documents[1]))->assertNotFound();
        $this->post(route('admin.applications.verdict',$apps[1]->id),['status'=>'rejected','comentario'=>'Prueba'])->assertNotFound();
        $this->post(route('admin.applications.assign'),['application_id'=>$apps[1]->id,'evaluator_ids'=>[$evaluators[0]->id]])->assertNotFound();
        $this->post(route('admin.applications.assign'),['application_id'=>$apps[0]->id,'evaluator_ids'=>[$evaluators[1]->id]])->assertNotFound();
        $this->delete(route('admin.evaluators.destroy',$evaluators[1]->id))->assertNotFound();
        $this->post(route('admin.recognitions.toggle'),['user_id'=>$evaluators[1]->id,'announcement_id'=>$apps[0]->announcement_id])->assertNotFound();
        $this->get(route('security.users.index'))->assertForbidden();
        $this->patch(route('profile.update'), ['name'=>$admin->name,'email'=>$admin->email,'institution_id'=>$b->id])->assertSessionHasErrors('institution_id');
        $this->assertEquals($a->id, $admin->fresh()->institution_id);
        $this->assertDatabaseHas('applications',['id'=>$apps[1]->id,'status'=>'approved']);
        $this->assertDatabaseHas('users',['id'=>$evaluators[1]->id]);
        $this->assertDatabaseCount('recognitions',0);
    }

    public function test_assignment_and_in_app_notification_are_saved_when_smtp_rejects_the_email(): void
    {
        [$admin, $institution, , $applications] = $this->fixture();
        $application = $applications[0];
        $evaluator = User::factory()->create(['institution_id' => $institution->id]);
        $evaluator->assignRole('Evaluador');

        $pendingMail = \Mockery::mock(\Illuminate\Mail\PendingMail::class);
        $pendingMail->shouldReceive('queue')->once()
            ->with(\Mockery::type(\App\Mail\EvaluatorAssigned::class))
            ->andThrow(new TransportException('525 5.7.1 Unauthorized IP address', 525));
        Mail::shouldReceive('to')->once()->with($evaluator->email)->andReturn($pendingMail);

        $this->actingAs($admin)->post(route('admin.applications.assign'), [
            'application_id' => $application->id,
            'evaluator_ids' => [$evaluator->id],
        ])->assertRedirect(route('admin.applications.index'))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('evaluations', [
            'application_id' => $application->id,
            'evaluator_id' => $evaluator->id,
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $evaluator->id,
            'type' => 'evaluator_assignment',
        ]);
    }

    public function test_admin_without_institution_sees_no_records_and_superadmin_keeps_global_access(): void
    {
        [$admin]=$this->fixture();
        $admin->update(['institution_id'=>null]);
        $this->actingAs($admin)->get(route('admin.applications.index'))->assertOk()->assertInertia(fn(Assert $p)=>$p->has('applications.data',0)->has('evaluators',0));
        $this->get(route('admin.dashboard'))->assertOk()->assertInertia(fn(Assert $p)=>$p->where('stats.total',0));
        $admin->assignRole('Super Admin');
        $this->get(route('admin.applications.index'))->assertOk()->assertInertia(fn(Assert $p)=>$p->has('applications.data',2)->has('evaluators',2));
    }
}
