<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\{User,Application,Announcement,Calendar,Evaluation};
use App\Services\AssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
class SingleEvaluatorFlowTest extends TestCase {
 use RefreshDatabase;
 private function fixture(): array {
  Mail::fake();
  foreach(['Evaluador','Admin'] as $role) Role::findOrCreate($role,'web');
  $evaluator=User::factory()->create();$evaluator->assignRole('Evaluador');
  $admin=User::factory()->create();$admin->assignRole('Admin');
  $state=\Illuminate\Support\Facades\DB::table('states')->insertGetId(['name'=>'Prueba','abbreviation'=>'PR']);
  $institution=\App\Models\Institution::create(['name'=>'Institución prueba','state_id'=>$state]);
  $admin->update(['institution_id'=>$institution->id]);
  $evaluator->update(['institution_id'=>$institution->id]);
  $teacher=User::factory()->create(['institution_id'=>$institution->id]);
  $announcement=Announcement::create(['name'=>'Prueba']);
  Calendar::create(['announcement_id'=>$announcement->id,'publication_start'=>now()->subDays(5),'registration_start'=>now()->subDays(4),'registration_end'=>now()->subDays(2),'evaluation_start'=>now()->subDay(),'evaluation_end'=>now()->addDays(3),'results_start'=>now()->addDays(4),'results_end'=>now()->addDays(5)]);
  $application=Application::create(['user_id'=>$teacher->id,'announcement_id'=>$announcement->id]);
  app(AssignmentService::class)->assignEvaluators($application->id,[$evaluator->id]);
  return [$evaluator,$admin,$application,Evaluation::where('application_id',$application->id)->firstOrFail()];
 }
 public function test_evaluator_without_level_can_review_and_admin_issues_final_verdict(): void {
  [$evaluator,$admin,$application,$evaluation]=$this->fixture();
  $this->actingAs($evaluator)->get(route('evaluator.dashboard'))->assertOk();
  $this->get(route('evaluator.evaluation.show',$evaluation->id))->assertOk();
  $this->put(route('evaluator.evaluation.update',$evaluation->id),['status'=>'approved','score'=>10,'answers'=>['1'=>['option_id'=>1,'score'=>10]]])->assertSessionHasNoErrors()->assertRedirect(route('evaluator.dashboard'));
  $this->assertEquals('approved',$evaluation->fresh()->status);
  $this->assertEquals('pending',$application->fresh()->status);
  $this->assertDatabaseCount('recognitions',0);
  $this->actingAs($admin)->post(route('admin.applications.verdict',$application->id),['status'=>'approved'])->assertSessionHasNoErrors()->assertRedirect(route('admin.applications.index'));
  $this->assertEquals('approved',$application->fresh()->status);
  $this->assertDatabaseCount('recognitions',0);
  $this->assertDatabaseMissing('recognitions',['user_id'=>$application->user_id,'type'=>'postulante','stage'=>'national','active'=>true]);
 }
 public function test_local_verdict_notifies_teacher_immediately_without_duplicate_on_resubmit(): void {
  foreach (['approved', 'rejected'] as $status) {
   [$evaluator,$admin,$application,$evaluation]=$this->fixture();
   $teacher=$application->user;
   Role::findOrCreate('Docente','web');
   $teacher->assignRole('Docente');
   $payload=['status'=>$status,'comentario'=>$status==='rejected'?'Documentaci?n insuficiente':null];
   $url=route('admin.applications.verdict',$application->id);
   $this->actingAs($admin)->post($url,$payload)->assertSessionHasNoErrors()->assertRedirect();
   $notification=\App\Models\Notification::where('type','local_application_verdict')->where('user_id',$teacher->id)->sole();
   $this->assertSame($status,$notification->data['status']);
   $this->assertSame($application->id,$notification->data['application_id']);
   $this->assertNull($notification->read_at);
   $this->assertStringContainsString($status==='approved'?'Has pasado a la etapa nacional':'no pasa a la etapa nacional',$notification->data['message']);
   if ($status==='rejected') $this->assertStringContainsString('Documentaci?n insuficiente',$notification->data['message']);
   $this->post($url,$payload)->assertSessionHasNoErrors()->assertRedirect();
   $this->assertSame(1,\App\Models\Notification::where('type','local_application_verdict')->where('user_id',$teacher->id)->count());
   $response=$this->actingAs($teacher)->getJson(route('teacher.notifications.index'))->assertOk();
   $this->assertStringContainsString($notification->title,$response->getContent());
   $this->actingAs($evaluator)->getJson(route('evaluator.notifications.index'))->assertOk()->assertJsonMissing(['id'=>$notification->id,'type'=>'local_application_verdict']);
  }
 }
 public function test_unassigned_evaluator_cannot_open_another_review_and_no_level_configuration_route_remains(): void {
  [$evaluator,$admin,$application,$evaluation]=$this->fixture();
  $other=User::factory()->create();$other->assignRole('Evaluador');
  $this->actingAs($other)->get(route('evaluator.evaluation.show',$evaluation->id))->assertNotFound();
  $this->actingAs($admin)->put('/admin/evaluators/'.$evaluator->id.'/configuration',[])->assertNotFound();
 }
}
