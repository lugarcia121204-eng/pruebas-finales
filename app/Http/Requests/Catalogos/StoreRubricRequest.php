<?php

namespace App\Http\Requests\Catalogos;

use App\Models\Rubric;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRubricRequest extends FormRequest
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
        return [
            'title' => ['required', 'string', 'max:255'],
            'stage' => ['sometimes', 'required', Rule::in(Rubric::STAGES)],
            'is_active' => ['boolean'],
            'questions' => ['nullable', 'array'],
            'questions.*.text' => ['required', 'string'],
            'questions.*.options' => ['nullable', 'array'],
            'questions.*.options.*.text' => ['required', 'string'],
            'questions.*.options.*.score' => ['required', 'integer', $this->input('stage', Rubric::LOCAL) === Rubric::NATIONAL ? 'min:0' : 'min:1', 'max:5'],
        ];
    }
}
