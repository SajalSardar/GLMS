<?php

namespace App\Http\Requests\Api\V1;

use App\Models\AcademicYear;
use App\Models\School;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAcademicYearRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $school = School::find($this->integer('school_id'));

        return $school !== null && ($this->user()?->can('create', [AcademicYear::class, $school]) ?? false);
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
                Rule::unique('academic_years', 'name')->withoutTrashed()->where('school_id', $this->integer('school_id')),
            ],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
            'is_current' => ['boolean'],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }
}
