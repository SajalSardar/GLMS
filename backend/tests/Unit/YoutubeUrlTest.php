<?php

namespace Tests\Unit;

use App\Support\YoutubeUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class YoutubeUrlTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function validUrls(): array
    {
        return [
            'watch?v=' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
            'watch?v= with trailing params' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=30s', 'dQw4w9WgXcQ'],
            'youtu.be short link' => ['https://youtu.be/dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
            'youtu.be with query' => ['https://youtu.be/dQw4w9WgXcQ?si=abc123', 'dQw4w9WgXcQ'],
            'embed' => ['https://www.youtube.com/embed/dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
            'shorts' => ['https://www.youtube.com/shorts/dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
            'no scheme' => ['youtube.com/watch?v=dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
        ];
    }

    #[DataProvider('validUrls')]
    public function test_extract_video_id_recognizes_every_supported_url_form(string $url, string $expectedId): void
    {
        $this->assertSame($expectedId, YoutubeUrl::extractVideoId($url));
    }

    public function test_extract_video_id_returns_null_for_an_unrecognized_url(): void
    {
        $this->assertNull(YoutubeUrl::extractVideoId('https://vimeo.com/12345678'));
    }

    public function test_extract_video_id_returns_null_for_a_truncated_id(): void
    {
        $this->assertNull(YoutubeUrl::extractVideoId('https://youtu.be/short'));
    }

    public function test_is_valid_video_id_accepts_eleven_characters(): void
    {
        $this->assertTrue(YoutubeUrl::isValidVideoId('dQw4w9WgXcQ'));
    }

    public function test_is_valid_video_id_rejects_wrong_length(): void
    {
        $this->assertFalse(YoutubeUrl::isValidVideoId('short'));
    }

    public function test_embed_url_builds_the_expected_url(): void
    {
        $this->assertSame('https://www.youtube.com/embed/dQw4w9WgXcQ?rel=0', YoutubeUrl::embedUrl('dQw4w9WgXcQ'));
    }
}
