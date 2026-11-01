<?php

namespace App\Support;

class YoutubeUrl
{
    private const ID_PATTERN = '/^[A-Za-z0-9_-]{11}$/';

    /**
     * Extract an 11-character YouTube video ID from any of the four
     * supported URL forms (watch?v=, youtu.be/, embed/, shorts/). Does
     * not require a scheme. Returns null for anything else, including a
     * recognized host with a too-short or malformed ID.
     */
    public static function extractVideoId(string $url): ?string
    {
        $patterns = [
            '/youtube\.com\/watch\?v=([A-Za-z0-9_-]{11})/',
            '/youtu\.be\/([A-Za-z0-9_-]{11})/',
            '/youtube\.com\/embed\/([A-Za-z0-9_-]{11})/',
            '/youtube\.com\/shorts\/([A-Za-z0-9_-]{11})/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url, $matches) === 1) {
                return $matches[1];
            }
        }

        return null;
    }

    public static function isValidVideoId(string $videoId): bool
    {
        return preg_match(self::ID_PATTERN, $videoId) === 1;
    }

    /**
     * The embeddable player URL for a video ID, with rel=0 so YouTube
     * doesn't surface "recommended" videos from other channels at the
     * end of playback.
     */
    public static function embedUrl(string $videoId): string
    {
        return "https://www.youtube.com/embed/{$videoId}?rel=0";
    }
}
