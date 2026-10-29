<?php

namespace App\Http\Requests\Security;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRoleRequest extends FormRequest
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
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $role = $this->route('role');
        $systemRole = $role instanceof \App\Models\Role
            && in_array($role->name, \App\Models\Role::SYSTEM_ROLES, true);

        return [
            'name'          => ['required', 'string', 'max:255', \Illuminate\Validation\Rule::unique('roles', 'name')->ignore($role?->id),
                ...($systemRole ? [\Illuminate\Validation\Rule::in([$role->name])] : [])],
            'guard_name'    => ['required', \Illuminate\Validation\Rule::in(['web'])],
            'description'   => 'required|max:255',
            'permissions'   => 'nullable|array',
            'permissions.*' => 'exists:permissions,id', // Valida que cada permiso exista
        ];
    }

    public function attributes(): array
    {
        return [
            'name'          => 'nombre',
            'guard_name'    => 'nombre de guardia',
            'description'   => 'descripción',
            'permissions'   => 'permisos',
            'permissions.*' => 'permiso',
        ];
    }

    public function messages(): array
    {
        return [
            'name.in' => 'El nombre de un rol del sistema no se puede cambiar. Puedes editar su descripcion y permisos.',
            'name.required'         => 'El nombre del rol es obligatorio',
            'name.max'              => 'El nombre no puede exceder 255 caracteres',
            'name.unique'           => 'Este nombre de rol ya está en uso',
            'guard_name.required'   => 'El guard name es obligatorio',
            'description.required'  => 'La descripción es obligatoria',
            'description.max'       => 'La descripción no puede exceder 255 caracteres',
            'permissions.array'     => 'Los permisos deben ser un arreglo',
            'permissions.*.exists'  => 'Uno de los permisos seleccionados no existe',
        ];
    }
}
