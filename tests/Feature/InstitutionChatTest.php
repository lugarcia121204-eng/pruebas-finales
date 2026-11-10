<?php

namespace Tests\Feature;

use App\Models\{Institution, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InstitutionChatTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        config(['chat.secret' => null]);
        foreach (['Admin', 'Docente', 'Evaluador', 'Admin Nacional'] as $role) Role::findOrCreate($role, 'web');
        $state = DB::table('states')->insertGetId(['name' => 'Prueba', 'abbreviation' => 'PR']);
        $a = Institution::create(['name' => 'Sede A', 'state_id' => $state]);
        $b = Institution::create(['name' => 'Sede B', 'state_id' => $state]);
        $users = [];
        foreach ([['Admin', $a->id], ['Docente', $a->id], ['Docente', $a->id], ['Admin', $b->id], ['Docente', $b->id], ['Evaluador', $a->id], ['Admin Nacional', null]] as [$role, $institution]) {
            $user = User::factory()->create(['institution_id' => $institution]);
            $user->assignRole($role); $users[] = $user;
        }
        return $users;
    }

    public function test_socket_session_uses_authenticated_identity_and_requires_a_verified_chat_role(): void
    {
        [$admin, $teacher, , , , $evaluator] = $this->fixture();
        $this->getJson(route('institution-chat.socket-session'))->assertUnauthorized();
        $this->actingAs($teacher)->getJson(route('institution-chat.socket-session'))
            ->assertOk()->assertJsonPath('user_id', $teacher->id)
            ->assertJsonPath('institution_id', $teacher->institution_id)->assertJsonStructure(['session']);
        $this->actingAs($evaluator)->getJson(route('institution-chat.socket-session'))->assertForbidden();
        $admin->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($admin)->getJson(route('institution-chat.socket-session'))->assertForbidden();
    }

    public function test_socket_events_follow_persisted_changes_and_do_not_lose_messages_on_socket_failure(): void
    {
        [$admin, $teacher] = $this->fixture();
        config(['chat.secret' => 'test-secret', 'chat.socket_url' => 'http://socket.test']);
        \Illuminate\Support\Facades\Http::fake(['socket.test/*' => \Illuminate\Support\Facades\Http::response('', 204)]);
        $response = $this->actingAs($admin)->postJson(route('institution-chat.send', $teacher->id), ['body' => 'Socket.IO'])->assertCreated();
        \Illuminate\Support\Facades\Http::assertSent(fn ($request) => $request['user_id'] === $teacher->id
            && $request['contact_id'] === $admin->id && $request['institution_id'] === $teacher->institution_id
            && $request['unread_count'] === 1 && $request->hasHeader('Authorization', 'Bearer test-secret'));
        $id = $response->json('id');
        $this->patchJson(route('institution-chat.update', ['contact' => $teacher->id, 'message' => $id]), ['body' => 'Editado'])->assertNoContent();
        $this->deleteJson(route('institution-chat.destroy', ['contact' => $teacher->id, 'message' => $id]))->assertNoContent();
        \Illuminate\Support\Facades\Http::assertSentCount(6);
        \Illuminate\Support\Facades\Http::fake(['socket.test/*' => \Illuminate\Support\Facades\Http::response('', 503)]);
        $this->postJson(route('institution-chat.send', $teacher->id), ['body' => 'Conservar aunque falle el socket'])->assertCreated();
        $this->assertDatabaseHas('institution_messages', ['body' => 'Conservar aunque falle el socket']);
        \Illuminate\Support\Facades\Http::fake(['socket.test/*' => \Illuminate\Support\Facades\Http::response('', 204)]);
        $this->withCredentials()->withCookie(config('session.cookie'), app('session')->getId());
        $session = $this->getJson(route('institution-chat.socket-session'))->assertOk()->json('session');
        $this->post(route('logout'))->assertRedirect(route('login'));
        \Illuminate\Support\Facades\Http::assertSent(fn ($request) => $request['type'] === 'revoke' && $request['session'] === $session);
    }

    public function test_contacts_and_messages_are_private_and_limited_to_the_same_institution(): void
    {
        [$admin, $teacher, $otherTeacher, $otherAdmin, $outsider, $evaluator] = $this->fixture();
        $this->actingAs($admin)->get(route('institution-chat.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('contacts', 2)->where('institution', 'Sede A'));
        $this->actingAs($teacher)->get(route('institution-chat.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('contacts', 1)->where('contacts.0.id', $admin->id));
        $this->postJson(route('institution-chat.send', $admin->id), ['body' => 'Mi consulta'])->assertCreated();
        $this->postJson(route('institution-chat.send', $otherAdmin->id), ['body' => 'No autorizado'])->assertNotFound();
        $this->postJson(route('institution-chat.send', $otherTeacher->id), ['body' => 'No autorizado'])->assertNotFound();
        $this->actingAs($admin)->postJson(route('institution-chat.send', $teacher->id), ['body' => 'Respuesta'])->assertCreated();
        foreach ([$outsider, $evaluator] as $contact) {
            $this->getJson(route('institution-chat.messages', $contact->id))->assertNotFound();
            $this->postJson(route('institution-chat.send', $contact->id), ['body' => 'No autorizado'])->assertNotFound();
            $this->postJson(route('institution-chat.read', $contact->id), ['through' => 100])->assertNotFound();
        }
        $this->actingAs($otherTeacher)->getJson(route('institution-chat.messages', $admin->id))->assertOk()->assertJsonCount(0, 'messages');
        $this->actingAs($teacher)->getJson(route('institution-chat.messages', $admin->id))->assertOk()->assertJsonCount(2, 'messages')
            ->assertJsonPath('messages.0.mine', true)->assertJsonPath('messages.1.mine', false);
        $this->assertDatabaseCount('institution_messages', 2);
    }

    public function test_administrator_messages_notify_only_the_recipient_and_link_to_the_conversation(): void
    {
        [$admin, $teacher, $otherTeacher, $otherAdmin, $outsider] = $this->fixture();
        $this->actingAs($admin)->postJson(route('institution-chat.send', $teacher->id), ['body' => 'Revisa tu expediente'])->assertCreated();
        $notification = \App\Models\Notification::firstOrFail();
        $this->assertEquals($teacher->id, $notification->user_id);
        $this->assertEquals($admin->id, $notification->data['contact_id']);
        $this->assertSame('institution_chat', $notification->type);
        $this->assertNull($notification->read_at);
        $this->postJson(route('institution-chat.send', $outsider->id), ['body' => 'No autorizado'])->assertNotFound();
        $this->assertDatabaseCount('notifications', 1);
        $this->actingAs($otherTeacher)->getJson(route('teacher.notifications.index'))->assertOk()->assertJsonPath('unread_count', 0);
        $this->postJson(route('teacher.notifications.mark-as-read', $notification->id))->assertNotFound();
        $this->deleteJson(route('teacher.notifications.destroy', $notification->id))->assertNotFound();
        $this->actingAs($teacher)->getJson(route('teacher.notifications.index'))->assertOk()->assertJsonPath('unread_count', 1)
            ->assertJsonPath('notifications.data.0.id', $notification->id);
        $this->get(route('institution-chat.index', ['contact' => $admin->id]))->assertOk()->assertInertia(fn (Assert $page) => $page->where('selectedContactId', $admin->id));
        $this->get(route('institution-chat.index', ['contact' => $otherAdmin->id]))->assertNotFound();
        $this->postJson(route('institution-chat.read', $admin->id), ['through' => $notification->data['message_id']])->assertNoContent();
        $this->assertNotNull($notification->fresh()->read_at);
        $this->getJson(route('teacher.notifications.index'))->assertJsonPath('unread_count', 0);
        $this->postJson(route('institution-chat.send', $admin->id), ['body' => 'Gracias'])->assertCreated();
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_only_sender_can_edit_or_delete_and_polling_synchronizes_changes(): void
    {
        [$admin, $teacher, $otherTeacher, $otherAdmin] = $this->fixture();
        $this->actingAs($admin)->postJson(route('institution-chat.send', $teacher->id), ['body' => 'Original'])->assertCreated();
        $id = DB::table('institution_messages')->value('id');
        $update = route('institution-chat.update', ['contact' => $teacher->id, 'message' => $id]);
        $delete = route('institution-chat.destroy', ['contact' => $teacher->id, 'message' => $id]);
        $this->actingAs($teacher)->patchJson(route('institution-chat.update', ['contact' => $admin->id, 'message' => $id]), ['body' => 'Ajeno'])->assertNotFound();
        $this->deleteJson(route('institution-chat.destroy', ['contact' => $admin->id, 'message' => $id]))->assertNotFound();
        foreach ([$otherTeacher, $otherAdmin] as $outsider) {
            $this->actingAs($outsider)->patchJson($update, ['body' => 'Ajeno'])->assertNotFound();
            $this->deleteJson($delete)->assertNotFound();
        }
        $this->actingAs($admin)->patchJson($update, ['body' => '   '])->assertUnprocessable();
        $this->patchJson($update, ['body' => str_repeat('a', 4001)])->assertUnprocessable();
        $this->patchJson($update, ['body' => 'Corregido'])->assertNoContent();
        $this->assertDatabaseHas('institution_messages', ['id' => $id, 'body' => 'Corregido']);
        $this->assertSame($admin->name.': Corregido', \App\Models\Notification::firstOrFail()->data['message']);
        $poll = route('institution-chat.messages', ['contact' => $admin->id, 'after' => $id, 'sync_from' => $id, 'sync_through' => $id]);
        $this->actingAs($teacher)->getJson($poll)->assertOk()->assertJsonCount(0, 'messages')->assertJsonPath('synced.0.body', 'Corregido');
        $this->actingAs($admin)->deleteJson($delete)->assertNoContent();
        $this->assertDatabaseMissing('institution_messages', ['id' => $id]);
        $this->assertDatabaseCount('notifications', 0);
        $this->actingAs($teacher)->getJson($poll)->assertOk()->assertJsonCount(0, 'synced');
        $this->postJson(route('institution-chat.send', $admin->id), ['body' => 'Mensaje docente'])->assertCreated();
        $own = DB::table('institution_messages')->value('id');
        $this->patchJson(route('institution-chat.update', ['contact' => $admin->id, 'message' => $own]), ['body' => 'Correccion docente'])->assertNoContent();
        $this->deleteJson(route('institution-chat.destroy', ['contact' => $admin->id, 'message' => $own]))->assertNoContent();
        $this->assertDatabaseCount('institution_messages', 0);
    }

    public function test_unread_cursors_validation_and_role_changes_are_enforced(): void
    {
        [$admin, $teacher, $otherTeacher, $otherAdmin, $outsider, $evaluator, $national] = $this->fixture();
        $this->actingAs($teacher)->postJson(route('institution-chat.send', $admin->id), ['body' => '   '])->assertUnprocessable();
        $this->postJson(route('institution-chat.send', $admin->id), ['body' => str_repeat('a', 4001)])->assertUnprocessable();
        $this->postJson(route('institution-chat.send', $admin->id), ['body' => '<script>alert(1)</script>'])->assertCreated();
        $id = DB::table('institution_messages')->value('id');
        $this->actingAs($admin)->get(route('institution-chat.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('contacts', fn ($contacts) => collect($contacts)->firstWhere('id', $teacher->id)['unread_count'] == 1));
        $this->getJson(route('institution-chat.messages', ['contact' => $teacher->id, 'after' => $id]))->assertOk()->assertJsonCount(0, 'messages');
        $this->postJson(route('institution-chat.read', $teacher->id), ['through' => $id])->assertNoContent();
        $this->assertNotNull(DB::table('institution_messages')->value('read_at'));
        foreach ([$evaluator, $national] as $user) $this->actingAs($user)->get(route('institution-chat.index'))->assertForbidden();
        $teacher->update(['institution_id' => null]);
        $this->actingAs($teacher)->get(route('institution-chat.index'))->assertForbidden();
        $this->actingAs($admin)->getJson(route('institution-chat.messages', $teacher->id))->assertNotFound();
    }
}
