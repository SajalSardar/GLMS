<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Classroom;
use App\Models\School;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreClassroomRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $school = School::find($this->integer('school_id'));

        return $school !== null && ($this->user()?->can('create', [Classroom::class, $school]) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'school_id' => ['required', 'integer', Rule::exists('schools', 'id')->withoutTrashed()],
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('classrooms', 'name')->withoutTrashed()->where('school_id', $this->integer('school_id')),
            ],
            'building' => ['nullable', 'string', 'max:255'],
            'floor' => ['nullable', 'string', 'max:50'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }
}
