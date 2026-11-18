<?php

namespace App\Http\Requests\Api\V1;

use App\Models\ClassRoutine;
use App\Models\ClassSchool;
use App\Models\School;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreClassRoutineRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $school = School::find($this->integer('school_id'));

        return $school !== null && ($this->user()?->can('create', [ClassRoutine::class, $school]) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'class_id' => ['required', 'integer', Rule::exists('school_classes', 'id')->withoutTrashed()],
            'school_id' => ['required', 'integer', Rule::exists('schools', 'id')->withoutTrashed()],
            'subject_id' => ['required', 'integer', Rule::exists('subjects', 'id')->withoutTrashed()],
            'teacher_id' => ['nullable', 'integer', Rule::exists('teacher_users', 'id')->withoutTrashed()],
            'classroom_id' => ['nullable', 'integer', Rule::exists('classrooms', 'id')->withoutTrashed()],
            'academic_year_id' => ['nullable', 'integer', Rule::exists('academic_years', 'id')->withoutTrashed()],
            'day_of_week' => [
                'required', 'string', Rule::in(ClassRoutine::DAYS_OF_WEEK),
                Rule::unique('class_routines', 'day_of_week')->withoutTrashed()->where(function ($query) {
                    $query->where('class_id', $this->integer('class_id'))
                        ->where('school_id', $this->integer('school_id'))
                        ->where('period_no', $this->integer('period_no'));
                }),
            ],
            'period_no' => ['required', 'integer', 'min:1', 'max:20'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i', 'after:start_time'],
        ];
    }

    /**
     * class_id/school_id existing individually isn't enough — the school
     * must actually have adopted this class via class_schools before a
     * routine can be created under it.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->hasAny(['class_id', 'school_id'])) {
                return;
            }

            $exists = ClassSchool::where('class_id', $this->integer('class_id'))
                ->where('school_id', $this->integer('school_id'))
                ->exists();

            if (! $exists) {
                $validator->errors()->add('class_id', 'This school has not adopted this class.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'day_of_week.unique' => 'This class already has a subject scheduled in this period on this day.',
        ];
    }
}
