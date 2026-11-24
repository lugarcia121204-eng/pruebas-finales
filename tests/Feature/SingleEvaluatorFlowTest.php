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
  $rubric=\App\Models\Rubric::firstOrCreate(['stage'=>'local','is_active'=>true],['title'=>'Rúbrica local']);
  if (!$rubric->questions()->exists()) $rubric->questions()->create(['text'=>'Criterio'])->options()->create(['text'=>'Cumple','score'=>10]);
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
 public function test_dashboard_combines_owned_local_and_national_pending_assignments(): void {
  [$evaluator,$admin,$application,$local]=$this->fixture();
  $nationalApplication=Application::create(['user_id'=>$application->user_id,'announcement_id'=>$application->announcement_id,'status'=>'approved']);
  $review=\App\Models\NationalReview::create(['application_id'=>$nationalApplication->id,'administrator_id'=>$admin->id,'assigned_by'=>$admin->id,'status'=>'pending']);
  $national=\App\Models\NationalEvaluation::create(['national_review_id'=>$review->id,'evaluator_id'=>$evaluator->id,'status'=>'pending']);
  $other=User::factory()->create();
  \App\Models\NationalEvaluation::create(['national_review_id'=>$review->id,'evaluator_id'=>$other->id,'status'=>'pending']);
  $response=$this->actingAs($evaluator)->get(route('evaluator.dashboard'))->assertOk();
  $response->assertInertia(fn (\Inertia\Testing\AssertableInertia $page)=>$page->component('Evaluator/Dashboard')
   ->where('stats.total',2)->where('stats.pendientes',2)->where('stats.evaluadas',0)->where('applications.total',2));
  $items=collect($response->inertiaProps('applications.data'));
  $this->assertSame($local->id,$items->firstWhere('stage','local')['evaluation_id']);
  $this->assertSame($national->id,$items->firstWhere('stage','national')['evaluation_id']);
  $this->get(route('evaluator.dashboard',['search'=>'no matching teacher']))->assertInertia(fn (\Inertia\Testing\AssertableInertia $page)=>$page->where('applications.total',0)->where('stats.total',2));
  $this->get(route('evaluator.dashboard',['rows'=>5]))->assertInertia(fn (\Inertia\Testing\AssertableInertia $page)=>$page->where('applications.per_page',5)->where('applications.total',2));
  $national->update(['status'=>'completed','score'=>10]);
  $this->get(route('evaluator.dashboard'))->assertInertia(fn (\Inertia\Testing\AssertableInertia $page)=>$page
   ->where('stats.total',2)->where('stats.pendientes',1)->where('stats.evaluadas',1)->where('applications.total',1)->where('applications.data.0.stage','local'));
  $national->update(['status'=>'pending']);
  $review->update(['status'=>'rejected']);
  $this->get(route('evaluator.dashboard'))->assertInertia(fn (\Inertia\Testing\AssertableInertia $page)=>$page->where('applications.total',1));
  $nationalApplication->delete();
  $this->get(route('evaluator.dashboard'))->assertInertia(fn (\Inertia\Testing\AssertableInertia $page)=>$page->where('stats.total',1)->where('applications.total',1));
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
