<?php
namespace Tests\Feature\Auth;
use App\Models\User;
use App\Mail\PasswordResetMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Mail, Hash};
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;
    public function test_reset_password_link_screen_can_be_rendered(): void
    {
        $this->get(route('password.request'))->assertOk();
    }
    public function test_reset_password_link_can_be_requested(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $this->post(route('password.email'), ['email' => $user->email])->assertSessionHasNoErrors();
        Mail::assertQueued(PasswordResetMail::class, fn ($mail) => $mail->hasTo($user->email) && str_contains($mail->resetUrl, '/reset-password/'));
    }
    public function test_reset_password_screen_can_be_rendered(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $this->post(route('password.email'), ['email' => $user->email])->assertSessionHasNoErrors();
        Mail::assertQueued(PasswordResetMail::class, function ($mail) {
            $this->get($mail->resetUrl)->assertOk();
            return true;
        });
    }
    public function test_password_can_be_reset_with_valid_token(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $this->post(route('password.email'), ['email' => $user->email])->assertSessionHasNoErrors();
        Mail::assertQueued(PasswordResetMail::class, function ($mail) use ($user) {
            $token = basename(parse_url($mail->resetUrl, PHP_URL_PATH));
            $this->post(route('password.update'), ['token' => $token, 'email' => $user->email, 'password' => 'newpassword123', 'password_confirmation' => 'newpassword123'])
                ->assertSessionHasNoErrors()->assertRedirect(route('login'));
            $this->assertTrue(Hash::check('newpassword123', $user->fresh()->password));
            return true;
        });
    }
    public function test_password_cannot_be_reset_with_invalid_token(): void
    {
        $user = User::factory()->create();
        $this->post(route('password.update'), ['token' => 'invalid-token', 'email' => $user->email, 'password' => 'newpassword123', 'password_confirmation' => 'newpassword123'])->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }
}
