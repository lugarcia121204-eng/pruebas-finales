<?php

namespace App\Http\Requests\Settings;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $user = $this->user();
            if ($user->hasAnyRole(['Admin', 'Admin Nacional']) && !$user->isSuperAdmin() && $this->has('institution_id')
                && (string) $this->input('institution_id') !== (string) $user->institution_id) {
                $validator->errors()->add('institution_id', 'Solo el Superadministrador puede cambiar la institución del administrador.');
            }
        });
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($this->user()->id),
            ],
            'institution_id' => ['nullable', 'exists:institutions,id'],
            'priority_area_id' => ['nullable', 'exists:priority_areas,id'],
            'sub_area_id' => ['nullable', 'exists:sub_areas,id'],
        ];
    }
}
