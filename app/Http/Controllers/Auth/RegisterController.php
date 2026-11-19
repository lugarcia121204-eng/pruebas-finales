<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\PriorityArea;
use App\Mail\VerificationCode;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use App\Http\Requests\Auth\RegisterUserRequest;
use Inertia\Inertia;

class RegisterController extends Controller
{
    /**
     * Muestra el formulario de registro
     */
    public function create()
    {
        return Inertia::render('Auth/Register', [
            'institutions' => \App\Models\Institution::orderBy('name')->get(['id', 'name']),
            'priorityAreas' => PriorityArea::with('subAreas')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * Muestra el formulario de registro para evaluadores
     */
    public function createEvaluador()
    {
        return Inertia::render('Auth/RegisterEvaluator', [
            'institutions' => \App\Models\Institution::orderBy('name')->get(['id', 'name']),
            'priorityAreas' => PriorityArea::with('subAreas')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * Maneja el registro del usuario
     */
    public function store(RegisterUserRequest $request)
    {
        $data = $request->validated();
        $targetRole = ($data['role_type'] ?? 'docente') === 'evaluador' ? 'Evaluador' : 'Docente';
        $verificationCode = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        try {
            $user = \Illuminate\Support\Facades\DB::transaction(function () use ($data, $targetRole, $verificationCode) {
                $matches = User::where('email', $data['email'])->orWhere('curp', $data['curp'])
                    ->lockForUpdate()->get();
                $user = $matches->first();

                if ($user) {
                    if ($matches->count() !== 1 || strtolower(trim($user->email)) !== $data['email'] || strtoupper(trim($user->curp ?? '')) !== $data['curp']
                        || $user->roles()->whereNotIn('name', ['Docente', 'Evaluador'])->exists()
                        || !Hash::check($data['password'], $user->password)) {
                        throw ValidationException::withMessages([
                            'email' => 'Los datos no coinciden con una cuenta habilitada para este registro. Usa el correo, CURP y contraseña de tu cuenta.',
                        ]);
                    }
                    if ($user->hasRole($targetRole)) {
                        throw ValidationException::withMessages([
                            'curp' => "Ya existe una cuenta registrada como {$targetRole} con este CURP.",
                        ]);
                    }
                    $requiresTwoFactor = $user->two_factor_secret
                        && (!\Laravel\Fortify\Fortify::confirmsTwoFactorAuthentication() || $user->two_factor_confirmed_at);
                    if ($requiresTwoFactor && (auth()->id() !== $user->id || !$user->hasVerifiedEmail())) {
                        throw ValidationException::withMessages([
                            'email' => 'Inicia sesión con tu verificación de dos factores antes de agregar otro rol.',
                        ]);
                    }
                } else {
                    $user = User::create([
                        'curp' => $data['curp'],
                        'name' => $data['name'],
                        'email' => $data['email'],
                        'password' => Hash::make($data['password']),
                        'institution_id' => $data['institution_id'],
                        'priority_area_id' => $data['priority_area_id'],
                        'sub_area_id' => $data['sub_area_id'],
                    ]);
                }

                $user->assignRole($targetRole);
                $user->forceFill([
                    'email_verified_at' => null,
                    'email_verification_code' => $verificationCode,
                    'email_verification_code_expires_at' => now()->addHours(24),
                ])->save();

                return $user;
            });
        } catch (\Illuminate\Database\UniqueConstraintViolationException $error) {
            throw ValidationException::withMessages(['email' => 'Ya existe una cuenta con ese correo o CURP. Inicia sesión para continuar.']);
        }

        try {
            Mail::to($user->email)->queue(new VerificationCode($user, $verificationCode));
            $mailStatus = 'Código de verificación enviado a tu correo electrónico.';
        } catch (\Throwable $error) {
            Log::error('No se pudo enviar el correo de verificación.', ['exception' => get_class($error)]);
            $mailStatus = 'Tu cuenta fue creada, pero no se pudo enviar el código. Solicita reenviarlo desde esta pantalla.';
        }

        \Illuminate\Support\Facades\Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('verification.notice')->with('status', $mailStatus);
    }
}
