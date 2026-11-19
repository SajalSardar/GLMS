<?php

return [
    /*
     * How many random questions a practice exam draws when exactly one
     * chapter was selected.
     */
    'single_chapter_question_count' => (int) env('PRACTICE_EXAM_SINGLE_CHAPTER_QUESTIONS', 20),

    /*
     * How many random questions a practice exam draws when two or more
     * chapters were selected.
     */
    'multi_chapter_question_count' => (int) env('PRACTICE_EXAM_MULTI_CHAPTER_QUESTIONS', 40),

    /*
     * Score-percentage cutoffs for the four result tiers: below
     * tier_pass_percent is "failed"; below tier_good_percent is "pass";
     * below tier_best_percent is "good"; at or above tier_best_percent
     * is "best". Computed client-side from score/total, but the cutoffs
     * themselves live here so they're configured in one place.
     */
    'tier_pass_percent' => (int) env('PRACTICE_EXAM_TIER_PASS_PERCENT', 40),
    'tier_good_percent' => (int) env('PRACTICE_EXAM_TIER_GOOD_PERCENT', 60),
    'tier_best_percent' => (int) env('PRACTICE_EXAM_TIER_BEST_PERCENT', 80),
];
