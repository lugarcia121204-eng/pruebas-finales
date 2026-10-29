<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered()
    {
        $response = $this->get(route('register'));

        $response->assertStatus(200);
    }

    public function test_new_users_can_register()
    {
        \Illuminate\Support\Facades\Mail::fake();
        \Spatie\Permission\Models\Role::findOrCreate('Docente', 'web');
        $state = \Illuminate\Support\Facades\DB::table('states')->insertGetId(['name' => 'Test', 'abbreviation' => 'TS']);
        $institution = \App\Models\Institution::create(['name' => 'Campus', 'state_id' => $state]);
        $area = \App\Models\PriorityArea::create(['name' => 'Area']);
        $sub = \App\Models\SubArea::create(['name' => 'Subarea', 'priority_area_id' => $area->id]);
        $response = $this->post(route('register.store'), [
            'curp' => 'TEST900101HDFABC01',
            'institution_id' => $institution->id,
            'priority_area_id' => $area->id,
            'sub_area_id' => $sub->id,
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertSessionHasNoErrors()->assertRedirect(route('verification.notice'));
        $user = \App\Models\User::where('email', 'test@example.com')->firstOrFail();
        $this->assertTrue($user->hasRole('Docente'));
        $this->assertNull($user->email_verified_at);
        \Illuminate\Support\Facades\Mail::assertQueued(\App\Mail\VerificationCode::class, fn ($mail) => $mail->hasTo($user->email));
    }
}
