<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;

class RegisterUserRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'curp' => strtoupper(trim((string) $this->input('curp'))),
            'email' => strtolower(trim((string) $this->input('email'))),
            'name' => trim((string) $this->input('name')),
        ]);
    }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Any guest can register
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'curp' => ['required', 'string', 'size:18', 'regex:/^[A-Z]{4}[0-9]{6}[HM][A-Z]{5}[A-Z0-9][0-9]$/D'],
            'name' => 'required|string|max:255',
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::notIn(['admin@gmail.com', 'superadmin@gmail.com']),
            ],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'institution_id' => 'required|exists:institutions,id',
            'priority_area_id' => 'required|exists:priority_areas,id',
            'sub_area_id' => ['required', Rule::exists('sub_areas', 'id')->where('priority_area_id', $this->input('priority_area_id'))],
            'role_type' => 'sometimes|string|in:evaluador,docente', // Optional parameter to determine role
        ];
    }

    public function messages(): array
    {
        return [
            'curp.regex' => 'La CURP debe tener un formato válido de 18 caracteres.',
            'sub_area_id.exists' => 'La subárea seleccionada debe pertenecer al área prioritaria.',
            'email.not_in' => 'Este correo esta reservado para la administracion del sistema y no puede usarse en registro publico.',
        ];
    }
}
