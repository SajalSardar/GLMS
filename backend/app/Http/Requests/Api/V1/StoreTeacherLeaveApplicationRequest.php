<?php

namespace App\Http\Requests\Api\V1;

use App\Models\TeacherLeaveApplication;
use App\Models\TeacherUser;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTeacherLeaveApplicationRequest extends FormRequest
{
    /**
     * A teacher submits their own leave application — not governed by
     * the Role/Permission system at all, same convention as
     * StoreStudentLeaveApplicationRequest: the actor just needs to be a
     * logged-in TeacherUser.
     */
    public function authorize(): bool
    {
        return $this->user() instanceof TeacherUser;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string|Closure>
     */
    public function rules(): array
    {
        $teacher = $this->user();

        return [
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date', 'after_or_equal:from_date'],
            'reason' => ['required', 'string', 'max:2000'],
            'delegate_teacher_id' => [
                'nullable',
                'integer',
                Rule::exists('teacher_users', 'id')->withoutTrashed(),
                function (string $attribute, mixed $value, Closure $fail) use ($teacher) {
                    if ($value === null || ! $teacher instanceof TeacherUser) {
                        return;
                    }

                    if ((int) $value === $teacher->id) {
                        $fail('You cannot name yourself as your own substitute.');

                        return;
                    }

                    $delegate = TeacherUser::find($value);

                    if ($delegate === null || $delegate->school_id !== $teacher->school_id) {
                        $fail('The selected substitute must be a teacher at your own school.');
                    }
                },
            ],
        ];
    }

    /**
     * Rejects a request whose date range overlaps an existing pending or
     * approved application by this same teacher — prevents two
     * conflicting requests/delegations coexisting. Runs after the field
     * rules above, so it's skipped if from_date/to_date already failed.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $teacher = $this->user();

            if (! $teacher instanceof TeacherUser) {
                return;
            }

            $fromDate = $this->input('from_date');
            $toDate = $this->input('to_date');

            if (! $fromDate || ! $toDate) {
                return;
            }

            $overlaps = TeacherLeaveApplication::query()
                ->where('teacher_id', $teacher->id)
                ->whereIn('status', ['pending', 'approved'])
                ->where('from_date', '<=', $toDate)
                ->where('to_date', '>=', $fromDate)
                ->exists();

            if ($overlaps) {
                $validator->errors()->add('from_date', 'You already have a pending or approved leave request that overlaps these dates.');
            }
        });
    }
}
