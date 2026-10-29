<?php
namespace Tests\Feature\Auth;
use App\Models\User;
use App\Http\Responses\LoginResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
class VerificationSessionTest extends TestCase
{
    use RefreshDatabase;
    public function test_guest_verification_survives_refresh_invalid_codes_and_repeated_resends(): void
    {
        Mail::fake();
        $user = User::factory()->unverified()->create(['email_verification_code'=>'123456','email_verification_code_expires_at'=>now()->addHour()]);
        $this->withSession(['verification.email'=>$user->email]);
        for($i=0;$i<3;$i++) $this->get(route('verification.notice'))->assertOk()->assertInertia(fn(Assert $p)=>$p->where('email',$user->email));
        $this->post(route('verification.verify.code'),['code'=>'000000'])->assertSessionHasErrors('code');
        for($i=0;$i<2;$i++) {
            $this->post(route('verification.resend'),['email'=>'someone-else@example.com'])->assertSessionHasNoErrors();
            $this->get(route('verification.notice'))->assertOk()->assertInertia(fn(Assert $p)=>$p->where('email',$user->email));
        }
        Mail::assertQueued(\App\Mail\VerificationCode::class,2);
        Mail::assertQueued(\App\Mail\VerificationCode::class,fn($mail)=>$mail->hasTo($user->email));
        $this->post(route('verification.verify.code'),['code'=>$user->fresh()->email_verification_code])->assertSessionHasNoErrors()->assertSessionMissing('verification.email');
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }
    public function test_missing_verification_context_redirects_to_login(): void
    {
        $this->get(route('verification.notice'))->assertRedirect(route('login'));
    }
    public function test_login_response_stores_persistent_context_after_logging_out_unverified_user(): void
    {
        Mail::fake();
        $user=User::factory()->unverified()->create();
        $this->actingAs($user);
        $request=\Illuminate\Http\Request::create('/login','POST');
        $session=app('session.store');$session->start();$request->setLaravelSession($session);
        app(LoginResponse::class)->toResponse($request);
        $this->assertGuest();
        $this->assertSame($user->email,$session->get('verification.email'));
        $session->ageFlashData();$session->ageFlashData();
        $this->assertSame($user->email,$session->get('verification.email'));
    }
}
