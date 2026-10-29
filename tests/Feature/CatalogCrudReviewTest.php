<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use Spatie\Permission\Models\Role;
class CatalogCrudReviewTest extends TestCase {
 use RefreshDatabase;
 private function admin(): void {
  Storage::fake('public'); Storage::fake('local'); Mail::fake(); Notification::fake();
  Role::findOrCreate('Super Admin','web'); $user=User::factory()->create(); $user->assignRole('Super Admin'); $this->actingAs($user);
 }
 public function test_main_catalogs_crud_and_validation(): void {
  $this->admin();
  $state=DB::table('states')->insertGetId(['name'=>'Prueba','abbreviation'=>'PR']);
  $area=DB::table('priority_areas')->insertGetId(['name'=>'Area de referencia']);
  foreach ([
   ['catalog.priority-areas.','priority_areas',['name'=>'Area prueba']],
   ['catalog.sub-areas.','sub_areas',['name'=>'Subarea prueba','priority_area_id'=>$area]],
   ['catalog.institutions.','institutions',['name'=>'Institucion prueba','state_id'=>$state]],
   ['catalog.documents.','catalog_documents',['name'=>'Documento prueba','description'=>'Descripcion','via'=>'larga','active'=>true]],
  ] as [$route,$table,$data]) {
   $this->get(route($route.'index'))->assertOk();
   $this->get(route($route.'create'))->assertOk();
   $this->post(route($route.'store'),[])->assertSessionHasErrors('name');
   $this->post(route($route.'store'),$data)->assertSessionHasNoErrors()->assertRedirect();
   $row=DB::table($table)->where('name',$data['name'])->first(); $this->assertNotNull($row,$table);
   $this->get(route($route.'edit',$row->id))->assertOk();
   $data['name'].=' editado';
   $this->put(route($route.'update',$row->id),$data)->assertSessionHasNoErrors()->assertRedirect();
   $this->assertDatabaseHas($table,['id'=>$row->id,'name'=>$data['name']]);
   $this->delete(route($route.'destroy',$row->id))->assertSessionHasNoErrors()->assertRedirect();
   if ($table==='catalog_documents') $this->assertDatabaseMissing($table,['id'=>$row->id]);
   else $this->assertSoftDeleted($table,['id'=>$row->id]);
  }
 }
 public function test_announcement_crud_and_calendar_validation(): void {
  $this->admin();
  $this->get(route('announcements.index'))->assertOk();
  $this->get(route('announcements.create'))->assertOk();
  $this->post(route('announcements.store'),[])->assertSessionHasErrors(['name','publication_start']);
  $data=['name'=>'Convocatoria prueba','description'=>'Prueba aislada','status'=>'pendiente'];
  foreach (['publication_start','registration_start','registration_end','evaluation_start','evaluation_end','results_start','results_end'] as $i=>$field) $data[$field]=now()->addDays($i+1)->toDateString();
  $this->post(route('announcements.store'),$data)->assertSessionHasNoErrors()->assertRedirect();
  $row=DB::table('announcements')->where('name',$data['name'])->first(); $this->assertNotNull($row);
  $this->get(route('announcements.edit',$row->id))->assertOk();
  $data['name']='Convocatoria editada';
  $this->put(route('announcements.update',$row->id),$data)->assertSessionHasNoErrors()->assertRedirect();
  $this->assertDatabaseHas('announcements',['id'=>$row->id,'name'=>$data['name']]);
  $this->delete(route('announcements.destroy',$row->id))->assertSessionHasNoErrors()->assertRedirect();
  $this->assertSoftDeleted('announcements',['id'=>$row->id]);
 }
 public function test_catalog_operations_require_permissions(): void {
  $this->actingAs(User::factory()->create());
  foreach (['catalog.priority-areas.','catalog.sub-areas.','catalog.institutions.','catalog.documents.','announcements.'] as $route) {
   $this->get(route($route.'index'))->assertForbidden();
   $this->get(route($route.'create'))->assertForbidden();
   $this->post(route($route.'store'),[])->assertForbidden();
  }
 }
}
