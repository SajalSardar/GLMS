<?php

namespace App\Http\Requests\Api\V1;

use App\Models\ClassRoutine;
use App\Models\ClassVersion;
use App\Models\Shift;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreShiftRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $school = ClassVersion::find($this->integer('class_version_id'))?->school;

        return $school !== null && ($this->user()?->can('create', [Shift::class, $school]) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'class_version_id' => ['required', 'integer', Rule::exists('class_versions', 'id')->withoutTrashed()],
            'academic_year_id' => ['nullable', 'integer', Rule::exists('academic_years', 'id')->withoutTrashed()],
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('shifts', 'name')->withoutTrashed()->where('class_version_id', $this->integer('class_version_id')),
            ],
            'bn_name' => ['nullable', 'string', 'max:255'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'days_of_week' => ['required', 'array', 'min:1'],
            'days_of_week.*' => [Rule::in(ClassRoutine::DAYS_OF_WEEK)],
            'period_duration_minutes' => ['nullable', 'integer', 'min:1', 'max:180'],
            'breaks' => ['nullable', 'array'],
            'breaks.*.start_time' => ['required', 'date_format:H:i'],
            'breaks.*.end_time' => ['required', 'date_format:H:i', 'after:breaks.*.start_time'],
            'breaks.*.label' => ['nullable', 'string', 'max:100'],
            'is_active' => ['boolean'],
        ];
    }
}
