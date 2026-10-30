<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateClassroomRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('classroom')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * school_id is immutable after creation — moving a classroom to a
     * different school isn't supported by this endpoint.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $classroom = $this->route('classroom');

        return [
            'name' => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique('classrooms', 'name')->ignore($classroom)->where('school_id', $classroom->school_id),
            ],
            'building' => ['nullable', 'string', 'max:255'],
            'floor' => ['nullable', 'string', 'max:50'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }
}
