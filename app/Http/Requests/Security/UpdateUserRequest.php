<?php

namespace App\Http\Requests\Security;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            'name'              => 'required|max:255',
            'email'             => 'required|email|max:255',
            'password'          => 'nullable|max:20',
            'roles'             => 'required|array',
            'roles.*'           => 'exists:roles,id',
            'institution_id' => [
                Rule::excludeIf(fn () => \Spatie\Permission\Models\Role::where('name', 'Admin Nacional')->whereIn('id', (array) $this->input('roles', []))->exists()
                    || !\Spatie\Permission\Models\Role::where('name', 'Admin')->whereIn('id', (array) $this->input('roles', []))->exists()),
                'required', 'integer', Rule::exists('institutions', 'id')->whereNull('deleted_at'),
            ],
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $email = $this->input('email');
            $roleIds = $this->input('roles', []);
            $userId = $this->id;

            // Verificar si el email ya existe con alguno de los roles asignados (excepto el usuario actual)
            $existingUser = \App\Models\User::where('email', $email)
                ->where('id', '!=', $userId)
                ->whereHas('roles', function ($query) use ($roleIds) {
                    $query->whereIn('roles.id', $roleIds);
                })
                ->first();

            if ($existingUser) {
                $roleName = $existingUser->getPrimaryRole();
                $validator->errors()->add('email', "Este correo ya está registrado con el rol de {$roleName}");
            }
        });
    }
    public function attributes(): array
    {
        return [
            'name'              => 'nombre',
            'email'             => 'correo electrónico',
            'password'          => 'contraseña',
            'roles'             => 'roles',
            'roles.*'           => 'rol',
        ];
    }

    public function messages(): array
    {
        return [
            'institution_id.required' => 'Selecciona la institución del administrador.',
            'institution_id.exists' => 'Selecciona una institución válida.',
            'name.required'         => 'El nombre completo es obligatorio',
            'name.max'              => 'El nombre no puede exceder 255 caracteres',
            'email.required'        => 'El correo electrónico es obligatorio',
            'email.email'           => 'El correo electrónico debe ser válido',
            'email.max'             => 'El correo electrónico no puede exceder 255 caracteres',
            'email.unique'          => 'Este correo electrónico ya está registrado',
            'password.max'          => 'La contraseña no puede exceder 20 caracteres',
            'roles.required'        => 'Debes asignar al menos un rol',
            'roles.array'           => 'Los roles deben ser un arreglo',
            'roles.*.exists'        => 'Uno de los roles seleccionados no existe',
        ];
    }
}
