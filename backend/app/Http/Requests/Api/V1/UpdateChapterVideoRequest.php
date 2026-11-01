<?php

namespace App\Http\Requests\Api\V1;

use App\Support\YoutubeUrl;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateChapterVideoRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('chapter_video')) ?? false;
    }

    /**
     * chapter_id is immutable after creation.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'youtube_url' => ['sometimes', 'string'],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'bn_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'instructor_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'duration_seconds' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'learning_objectives' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'completion_threshold_percent' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
            'order_index' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000'],
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
