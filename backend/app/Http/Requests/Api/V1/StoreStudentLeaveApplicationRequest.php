<?php

namespace App\Http\Requests\Api\V1;

use App\Models\StudentUser;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreStudentLeaveApplicationRequest extends FormRequest
{
    /**
     * A student submits their own leave application — not governed by
     * the Role/Permission system at all, same as practice exam creation
     * and chapter-materials/mine: the actor just needs to be a logged-in
     * StudentUser. Which fields they're scoped to (school/district/
     * class/section/shift/class_version) is resolved server-side from
     * their own profile in the controller, never accepted here.
     */
    public function authorize(): bool
    {
        return $this->user() instanceof StudentUser;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date', 'after_or_equal:from_date'],
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
