<?php

namespace App\Http\Requests\Api\V1;

use App\Models\ChapterVideo;
use App\Support\YoutubeUrl;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreChapterVideoRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', ChapterVideo::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'chapter_id' => ['required', 'integer', Rule::exists('chapters', 'id')->withoutTrashed()],
            'youtube_url' => ['required', 'string'],
            'title' => ['required', 'string', 'max:255'],
            'bn_title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'instructor_name' => ['nullable', 'string', 'max:255'],
            'duration_seconds' => ['nullable', 'integer', 'min:1'],
            'learning_objectives' => ['nullable', 'string', 'max:2000'],
            'completion_threshold_percent' => ['nullable', 'integer', 'min:1', 'max:100'],
            'order_index' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'is_enabled' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Reject a youtube_url that doesn't resolve to a valid video ID.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $url = $this->input('youtube_url');

            if ($url && YoutubeUrl::extractVideoId($url) === null) {
                $validator->errors()->add('youtube_url', 'This is not a recognized YouTube video URL.');
            }
        });
    }
}
