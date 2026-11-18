<?php

namespace App\Http\Requests\Api\V1;

use App\Models\ClassSchool;
use App\Models\ClassVersion;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreClassVersionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $classSchool = ClassSchool::where('class_id', $this->integer('class_id'))
            ->where('school_id', $this->integer('school_id'))
            ->first();

        return $classSchool !== null && ($this->user()?->can('create', [ClassVersion::class, $classSchool->school]) ?? false);
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
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('class_versions', 'name')->withoutTrashed()->where('class_id', $this->integer('class_id')),
            ],
            'bn_name' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * class_id/school_id existing individually isn't enough — the school
     * must actually have adopted this class via class_schools before a
     * version can be created under it.
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
}
