<?php
namespace Tests\Feature;
use App\Models\{User, Institution, Application, Announcement};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\{Role, Permission};
use Tests\TestCase;
class AdministrativeScreensTest extends TestCase
{
    use RefreshDatabase;
    public function test_documents_and_request_control_render_and_remain_scoped_to_own_institution(): void
    {
        Role::findOrCreate('Admin','web');
        foreach(['documents.index','documents.show','documents.download','requests.index','requests.show'] as $permission) Permission::findOrCreate($permission,'web');
        $state=DB::table('states')->insertGetId(['name'=>'Prueba','abbreviation'=>'PR']);
        $institution=Institution::create(['name'=>'Sede A','state_id'=>$state]);
        $other=Institution::create(['name'=>'Sede B','state_id'=>$state]);
        $admin=User::factory()->create(['institution_id'=>$institution->id]);$admin->assignRole('Admin');
        $admin->givePermissionTo(['documents.index','documents.show','documents.download','requests.index','requests.show']);
        $teacher=User::factory()->create(['institution_id'=>$institution->id]);
        $outsider=User::factory()->create(['institution_id'=>$other->id]);
        $announcement=Announcement::create(['name'=>'Revision']);
        $application=Application::create(['user_id'=>$teacher->id,'announcement_id'=>$announcement->id,'status'=>'approved']);
        Application::create(['user_id'=>$teacher->id,'announcement_id'=>$announcement->id,'status'=>'approved']);
        $foreign=Application::create(['user_id'=>$outsider->id,'announcement_id'=>$announcement->id,'status'=>'approved']);
        $this->actingAs($admin)->get(route('security.documents.index'))->assertOk()->assertInertia(fn(Assert $p)=>$p->component('Admin/Documents/Index')->has('applications.data',2));
        $this->get(route('security.documents.show',$application->id))->assertOk()->assertInertia(fn(Assert $p)=>$p->component('Admin/Documents/Show'));
        $this->get(route('security.documents.show',$foreign->id))->assertNotFound();
        $this->get(route('applications.control.index'))->assertOk()->assertInertia(fn(Assert $p)=>$p->component('Admin/RequestControl/Index')->has('institutions.data',1)->where('institutions.data.0.approved_count',2));
        $this->get(route('applications.control.show',$institution->id))->assertOk()->assertInertia(fn(Assert $p)=>$p->component('Admin/RequestControl/Show')->has('applications.data',2));
        $this->get(route('applications.control.show',$other->id))->assertNotFound();
        $this->getJson(route('security.documents.index',['rows'=>-1]))->assertUnprocessable();
        $this->getJson(route('security.documents.index',['direction'=>'invalid']))->assertUnprocessable();
    }
}
