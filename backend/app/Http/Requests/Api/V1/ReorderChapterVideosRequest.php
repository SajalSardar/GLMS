<?php

namespace App\Http\Requests\Api\V1;

use App\Models\ChapterVideo;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ReorderChapterVideosRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('reorder', ChapterVideo::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'chapter_id' => ['required', 'integer', Rule::exists('chapters', 'id')->withoutTrashed()],
            'ordered_ids' => ['required', 'array', 'min:1'],
            'ordered_ids.*' => ['integer', Rule::exists('chapter_videos', 'id')->withoutTrashed()],
        ];
    }

    /**
     * Every ID in ordered_ids must belong to the given chapter_id — a
     * plain Rule::exists can't express that relational constraint.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $chapterId = $this->input('chapter_id');
            $ids = $this->input('ordered_ids', []);

            if (! $chapterId || ! is_array($ids) || $ids === []) {
                return;
            }

            $matching = ChapterVideo::where('chapter_id', $chapterId)->whereIn('id', $ids)->count();

            if ($matching !== count($ids)) {
                $validator->errors()->add('ordered_ids', 'One or more video IDs do not belong to the given chapter.');
            }
        });
    }
}
