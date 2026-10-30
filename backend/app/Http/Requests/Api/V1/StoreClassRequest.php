<?php

namespace App\Http\Requests\Api\V1;

use App\Models\School;
use App\Models\SchoolClass;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreClassRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $school = School::find($this->integer('school_id'));

        return $school !== null && ($this->user()?->can('create', [SchoolClass::class, $school]) ?? false);
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
                Rule::unique('school_classes', 'name')->withoutTrashed()->where(function ($query) {
                    $query->where('school_id', $this->integer('school_id'));

                    $this->filled('section')
                        ? $query->where('section', $this->input('section'))
                        : $query->whereNull('section');
                }),
            ],
            'bn_name' => ['nullable', 'string', 'max:255'],
            'numeric_level' => ['nullable', 'integer', 'min:1', 'max:12'],
            'section' => ['nullable', 'string', 'max:10'],
        ];
    }
}
