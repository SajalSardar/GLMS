<?php

namespace App\Support;

use App\Models\Section;
use App\Models\Shift;
use App\Models\Subject;
use App\Models\SubjectTeacherAssignment;
use App\Models\TimetableEntry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Fills every section under a Shift with conflict-free TimetableEntry
 * drafts for each assigned subject's full required_weekly_periods —
 * Phase 5.2 of the timetable-management requirements doc. See
 * docs/superpowers/specs/2026-11-05-automatic-timetable-generation-design.md
 * for the algorithm this implements: most-constrained-subject-first
 * greedy placement using TimetableConflictChecker for every hard
 * constraint, per-section all-or-nothing, then a bounded local-search
 * pass to improve soft constraints.
 */
class TimetableGenerator
{
    private const MAX_LOCAL_SEARCH_ITERATIONS = 50;

    public function __construct(
        private TimetableConflictChecker $conflictChecker,
        private TimetablePeriodGrid $periodGrid,
    ) {}

    /**
     * @return array{generated: list<array{section_id: int, entries_created: int}>, failed: list<array{section_id: int, shortfalls: list<array{subject_id: int, subject_name: string, required: int, placed: int, reason: string}>}>}
     */
    public function generate(Shift $shift): array
    {
        $grid = $this->periodGrid->build($shift);
        $generated = [];
        $failed = [];

        // Deterministic order: sections are processed id-ascending (not
        // guaranteed by the bare relation), since the first section
        // processed gets first pick of any shared teacher's open slots —
        // this is the one behavior-affecting ordering choice the spec
        // leaves to the implementation.
        foreach ($shift->sections()->orderBy('id')->get() as $section) {
            $result = $this->generateForSection($section, $grid);

            if ($result['shortfalls'] === []) {
                $generated[] = ['section_id' => $section->id, 'entries_created' => $result['entries_created']];
            } else {
                $failed[] = ['section_id' => $section->id, 'shortfalls' => $result['shortfalls']];
            }
        }

        return ['generated' => $generated, 'failed' => $failed];
    }

    /**
     * @param  list<array{day_of_week: string, period_no: int, start_time: string, end_time: string}>  $grid
     * @return array{entries_created: int, shortfalls: list<array{subject_id: int, subject_name: string, required: int, placed: int, reason: string}>}
     */
    private function generateForSection(Section $section, array $grid): array
    {
        DB::beginTransaction();

        TimetableEntry::where('section_id', $section->id)->where('status', 'draft')->delete();

        $entriesCreated = 0;
        $shortfalls = [];

        foreach ($this->orderSubjectsByConstraint($section, $grid) as [$subject, $assignment, $required]) {
            $result = $this->placeSubject($section, $subject, $assignment, $required, $grid);
            $entriesCreated += count($result['placed']);

            if ($result['shortfall'] !== null) {
                $shortfalls[] = $result['shortfall'];
            }
        }

        if ($shortfalls !== []) {
            DB::rollBack();

            return ['entries_created' => 0, 'shortfalls' => $shortfalls];
        }

        $this->runLocalSearch($section);
        DB::commit();

        return ['entries_created' => $entriesCreated, 'shortfalls' => []];
    }

    /**
     * Most-constrained-subject-first: a subject with fewer currently
     * conflict-free candidate slots for its assigned teacher is placed
     * before one with many, so it never loses its only viable slots to a
     * less-constrained subject placed earlier. A subject with no teacher
     * assignment at all sorts first (it has zero options) and is reported
     * as an immediate full shortfall.
     *
     * @param  list<array{day_of_week: string, period_no: int, start_time: string, end_time: string}>  $grid
     * @return list<array{0: Subject, 1: ?SubjectTeacherAssignment, 2: int}>
     */
    private function orderSubjectsByConstraint(Section $section, array $grid): array
    {
        $classVersion = $section->shift->classVersion;
        $rows = [];

        foreach ($classVersion->subjects as $subject) {
            $required = $subject->pivot->required_weekly_periods;

            if ($required === null) {
                continue;
            }

            $assignment = SubjectTeacherAssignment::where('section_id', $section->id)
                ->where('subject_id', $subject->id)
                ->first();

            if (! $assignment) {
                $rows[] = [$subject, null, $required, 0];

                continue;
            }

            $availableCount = 0;

            foreach ($grid as $candidate) {
                $conflict = $this->conflictChecker->conflictReason([
                    'teacher_id' => $assignment->teacher_id,
                    'classroom_id' => $section->default_classroom_id,
                    'day_of_week' => $candidate['day_of_week'],
                    'period_no' => $candidate['period_no'],
                ]);

                if ($conflict === null) {
                    $availableCount++;
                }
            }

            $rows[] = [$subject, $assignment, $required, $availableCount];
        }

        usort($rows, fn (array $a, array $b): int => $a[3] <=> $b[3]);

        return array_map(fn (array $row): array => [$row[0], $row[1], $row[2]], $rows);
    }

    /**
     * @param  list<array{day_of_week: string, period_no: int, start_time: string, end_time: string}>  $grid
     * @return array{placed: list<array{day_of_week: string, period_no: int}>, shortfall: ?array{subject_id: int, subject_name: string, required: int, placed: int, reason: string}}
     */
    private function placeSubject(Section $section, Subject $subject, ?SubjectTeacherAssignment $assignment, int $required, array $grid): array
    {
        if ($assignment === null) {
            return [
                'placed' => [],
                'shortfall' => [
                    'subject_id' => $subject->id,
                    'subject_name' => $subject->name,
                    'required' => $required,
                    'placed' => 0,
                    'reason' => 'No teacher is assigned to this subject for this section.',
                ],
            ];
        }

        $placedSlots = [];
        $lastReason = 'This shift has no remaining period that fits this subject\'s constraints.';

        for ($i = 0; $i < $required; $i++) {
            $outcome = $this->bestCandidate($section, $subject, $assignment, $grid, $placedSlots, $lastReason);

            if ($outcome['candidate'] === null) {
                $lastReason = $outcome['reason'];

                break;
            }

            $candidate = $outcome['candidate'];

            TimetableEntry::createOrRestore([
                'subject_id' => $subject->id,
                'teacher_id' => $assignment->teacher_id,
                'classroom_id' => $section->default_classroom_id,
                'day_of_week' => $candidate['day_of_week'],
                'period_no' => $candidate['period_no'],
                'start_time' => $candidate['start_time'],
                'end_time' => $candidate['end_time'],
                'section_id' => $section->id,
                'academic_year_id' => $section->shift->academic_year_id,
                'school_id' => $section->school_id,
                'district_id' => $section->district_id,
            ]);

            $placedSlots[] = ['day_of_week' => $candidate['day_of_week'], 'period_no' => $candidate['period_no']];
        }

        if (count($placedSlots) === $required) {
            return ['placed' => $placedSlots, 'shortfall' => null];
        }

        return [
            'placed' => $placedSlots,
            'shortfall' => [
                'subject_id' => $subject->id,
                'subject_name' => $subject->name,
                'required' => $required,
                'placed' => count($placedSlots),
                'reason' => $lastReason,
            ],
        ];
    }

    /**
     * Scans the grid in three preference tiers — a day this subject
     * hasn't used yet, then a day that wouldn't create 3+ consecutive
     * periods for it, then anything left — returning the first
     * hard-constraint-valid candidate found, or null with the last
     * rejection reason encountered if none exists.
     *
     * @param  list<array{day_of_week: string, period_no: int, start_time: string, end_time: string}>  $grid
     * @param  list<array{day_of_week: string, period_no: int}>  $alreadyPlaced
     * @return array{candidate: ?array{day_of_week: string, period_no: int, start_time: string, end_time: string}, reason: string}
     */
    private function bestCandidate(Section $section, Subject $subject, SubjectTeacherAssignment $assignment, array $grid, array $alreadyPlaced, string $fallbackReason): array
    {
        $usedDays = array_unique(array_column($alreadyPlaced, 'day_of_week'));

        $tiers = [
            fn (array $c): bool => ! in_array($c['day_of_week'], $usedDays, true),
            fn (array $c): bool => ! $this->wouldCreateTripleConsecutive($c, $alreadyPlaced),
            fn (array $c): bool => true,
        ];

        $lastReason = $fallbackReason;

        foreach ($tiers as $tierFilter) {
            foreach ($grid as $candidateSlot) {
                $alreadyHere = array_filter(
                    $alreadyPlaced,
                    fn (array $p): bool => $p['day_of_week'] === $candidateSlot['day_of_week'] && $p['period_no'] === $candidateSlot['period_no']
                );

                if ($alreadyHere !== []) {
                    continue;
                }

                if (! $tierFilter($candidateSlot)) {
                    continue;
                }

                $attributes = [
                    'subject_id' => $subject->id,
                    'teacher_id' => $assignment->teacher_id,
                    'classroom_id' => $section->default_classroom_id,
                    'day_of_week' => $candidateSlot['day_of_week'],
                    'period_no' => $candidateSlot['period_no'],
                    'start_time' => $candidateSlot['start_time'],
                    'end_time' => $candidateSlot['end_time'],
                ];

                $error = $this->conflictChecker->invalidReason($section, $attributes)
                    ?? $this->conflictChecker->conflictReason($attributes);

                if ($error === null) {
                    return ['candidate' => $candidateSlot, 'reason' => ''];
                }

                $lastReason = $error['message'];
            }
        }

        return ['candidate' => null, 'reason' => $lastReason];
    }

    /**
     * @param  array{day_of_week: string, period_no: int}  $candidateSlot
     * @param  list<array{day_of_week: string, period_no: int}>  $alreadyPlaced
     */
    private function wouldCreateTripleConsecutive(array $candidateSlot, array $alreadyPlaced): bool
    {
        $sameDayPeriods = array_values(array_map(
            fn (array $p): int => $p['period_no'],
            array_filter($alreadyPlaced, fn (array $p): bool => $p['day_of_week'] === $candidateSlot['day_of_week'])
        ));
        $sameDayPeriods[] = $candidateSlot['period_no'];
        sort($sameDayPeriods);

        $consecutive = 1;
        $max = 1;

        for ($i = 1; $i < count($sameDayPeriods); $i++) {
            if ($sameDayPeriods[$i] === $sameDayPeriods[$i - 1] + 1) {
                $consecutive++;
                $max = max($max, $consecutive);
            } else {
                $consecutive = 1;
            }
        }

        return $max >= 3;
    }

    /**
     * Bounded best-effort polish: repeatedly try swapping two of this
     * section's placed entries (different subjects only — swapping
     * identical-subject slots can never change the score) and keep the
     * swap only if both resulting placements are still hard-constraint
     * valid AND it reduces the soft-constraint score. This check is
     * deliberately conservative: it excepts only the entry being moved,
     * not its swap partner, from the conflict query, so a same-teacher-
     * or same-classroom coincidence between the two can cause a valid
     * swap to be rejected as a false conflict. That is a safe failure —
     * it only means fewer swaps happen, never an invalid final state.
     */
    private function runLocalSearch(Section $section): void
    {
        for ($i = 0; $i < self::MAX_LOCAL_SEARCH_ITERATIONS; $i++) {
            $entries = TimetableEntry::where('section_id', $section->id)->where('status', 'draft')->get();

            if ($entries->count() < 2) {
                return;
            }

            [$a, $b] = $entries->random(2)->values()->all();

            if ($a->subject_id === $b->subject_id) {
                continue;
            }

            $scoreBefore = $this->softScore($entries);

            $aOriginal = ['day_of_week' => $a->day_of_week, 'period_no' => $a->period_no, 'start_time' => $a->start_time, 'end_time' => $a->end_time];
            $bOriginal = ['day_of_week' => $b->day_of_week, 'period_no' => $b->period_no, 'start_time' => $b->start_time, 'end_time' => $b->end_time];

            $aBaseAttributes = ['subject_id' => $a->subject_id, 'teacher_id' => $a->teacher_id, 'classroom_id' => $a->classroom_id, 'section_id' => $a->section_id, 'academic_year_id' => $a->academic_year_id, 'school_id' => $a->school_id, 'district_id' => $a->district_id];
            $bBaseAttributes = ['subject_id' => $b->subject_id, 'teacher_id' => $b->teacher_id, 'classroom_id' => $b->classroom_id, 'section_id' => $b->section_id, 'academic_year_id' => $b->academic_year_id, 'school_id' => $b->school_id, 'district_id' => $b->district_id];

            $bSlotAsStrings = ['day_of_week' => $b->day_of_week, 'period_no' => $b->period_no, 'start_time' => $b->start_time?->format('H:i'), 'end_time' => $b->end_time?->format('H:i')];
            $aSlotAsStrings = ['day_of_week' => $a->day_of_week, 'period_no' => $a->period_no, 'start_time' => $a->start_time?->format('H:i'), 'end_time' => $a->end_time?->format('H:i')];

            $aSwapped = [...$aBaseAttributes, ...$bSlotAsStrings];
            $bSwapped = [...$bBaseAttributes, ...$aSlotAsStrings];

            $aValid = ($this->conflictChecker->invalidReason($section, $aSwapped, $a->id) ?? $this->conflictChecker->conflictReason($aSwapped, $a->id)) === null;
            $bValid = ($this->conflictChecker->invalidReason($section, $bSwapped, $b->id) ?? $this->conflictChecker->conflictReason($bSwapped, $b->id)) === null;

            if (! $aValid || ! $bValid) {
                continue;
            }

            $a->forceDelete();
            $b->forceDelete();

            TimetableEntry::createOrRestore($aSwapped);
            TimetableEntry::createOrRestore($bSwapped);

            $scoreAfter = $this->softScore(TimetableEntry::where('section_id', $section->id)->where('status', 'draft')->get());

            if ($scoreAfter >= $scoreBefore) {
                TimetableEntry::where('section_id', $section->id)
                    ->where('day_of_week', $aSwapped['day_of_week'])->where('period_no', $aSwapped['period_no'])
                    ->forceDelete();
                TimetableEntry::where('section_id', $section->id)
                    ->where('day_of_week', $bSwapped['day_of_week'])->where('period_no', $bSwapped['period_no'])
                    ->forceDelete();

                TimetableEntry::createOrRestore([...$aBaseAttributes, ...$aOriginal]);
                TimetableEntry::createOrRestore([...$bBaseAttributes, ...$bOriginal]);
            }
        }
    }

    /**
     * Lower is better: count of same-subject slots beyond the first on
     * the same day, summed across every subject — a crude proxy for
     * "spread this subject across the week."
     *
     * @param  Collection<int, TimetableEntry>  $entries
     */
    private function softScore($entries): int
    {
        return $entries
            ->groupBy(fn (TimetableEntry $e): string => $e->subject_id.'-'.$e->day_of_week)
            ->sum(fn ($group): int => max(0, $group->count() - 1));
    }
}
