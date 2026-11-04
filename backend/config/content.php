<?php

return [
    /*
     * Maximum upload size, in kilobytes, for a book or chapter-material PDF.
     */
    'max_pdf_size_kb' => (int) env('MAX_PDF_SIZE_KB', 51200),

    /*
     * How long a signed download/preview link stays valid.
     */
    'signed_download_expiry_minutes' => (int) env('SIGNED_DOWNLOAD_EXPIRY_MINUTES', 5),

    /*
     * The filesystem disk PDFs are stored on — must be a private disk.
     */
    'pdf_disk' => env('CONTENT_PDF_DISK', 'local'),

    /*
     * Default percentage-of-duration a student must reach for a video to
     * count as completed, used when a ChapterVideo has no per-video
     * override (completion_threshold_percent).
     */
    'video_completion_threshold_percent' => (int) env('VIDEO_COMPLETION_THRESHOLD_PERCENT', 90),

    /*
     * The filesystem disk question/option images are stored on — public,
     * unlike pdf_disk, because an exam-taking student needs the image to
     * stay visible for the whole attempt (can outlast a 5-minute signed
     * link) and it isn't confidential the way a licensed PDF is.
     */
    'question_image_disk' => env('CONTENT_QUESTION_IMAGE_DISK', 'public'),

    /*
     * Maximum upload size, in kilobytes, for a question or option image.
     */
    'max_image_size_kb' => (int) env('MAX_IMAGE_SIZE_KB', 2048),
];
