<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Section;
use App\Models\Shift;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSectionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $school = Shift::find($this->integer('shift_id'))?->school;

        return $school !== null && ($this->user()?->can('create', [Section::class, $school]) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'shift_id' => ['required', 'integer', Rule::exists('shifts', 'id')->withoutTrashed()],
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('sections', 'name')->withoutTrashed()->where('shift_id', $this->integer('shift_id')),
            ],
            'bn_name' => ['nullable', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:200'],
            'class_teacher_id' => ['nullable', 'integer', Rule::exists('teacher_users', 'id')->withoutTrashed()],
            'default_classroom_id' => ['nullable', 'integer', Rule::exists('classrooms', 'id')->withoutTrashed()],
            'is_active' => ['boolean'],
        ];
    }
}
