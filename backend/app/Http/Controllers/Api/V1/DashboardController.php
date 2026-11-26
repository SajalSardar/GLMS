<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Holiday;
use App\Models\School;
use App\Models\StudentProfile;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    /**
     * One aggregate read for the Management Portal's dashboard, rather
     * than one round trip per widget. Each section is independently
     * gated on the same permission its own dedicated endpoint already
     * requires (schools.view, teacher_profiles.view, student_profiles.view,
     * attendances.view, holidays.view) and is `null` in the response when
     * the actor lacks it — the frontend hides a widget whose field is
     * null, the same convention ExamAttemptResource already uses for
     * is_correct. Every count below is a plain Eloquent query, so
     * TenantScope narrows it to the actor's own school/district for free,
     * exactly like every other report in this app.
     */
    public function summary(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'data' => [
                'schools_count' => $user->hasPermission('schools.view') ? School::count() : null,
                'teachers_count' => $user->hasPermission('teacher_profiles.view') ? TeacherProfile::count() : null,
                'students_count' => $user->hasPermission('student_profiles.view') ? StudentProfile::count() : null,
                'attendance_today' => $this->attendanceToday($user),
                'attendance_trend' => $this->attendanceTrend($user),
                'upcoming_holidays' => $this->upcomingHolidays($user),
                'recent_students' => $this->recentStudents($user),
            ],
        ]);
    }

    /**
     * @return array{date: string, present: int, absent: int, leave: int, total: int, present_percent: float|null}|null
     */
    private function attendanceToday(User $user): ?array
    {
        if (! $user->hasPermission('attendances.view')) {
            return null;
        }

        return $this->attendanceCountsFor(Carbon::today());
    }

    /**
     * @return list<array{date: string, present: int, absent: int, leave: int, total: int, present_percent: float|null}>|null
     */
    private function attendanceTrend(User $user): ?array
    {
        if (! $user->hasPermission('attendances.view')) {
            return null;
        }

        return collect(range(6, 0))
            ->map(fn (int $daysAgo) => $this->attendanceCountsFor(Carbon::today()->subDays($daysAgo)))
            ->all();
    }

    /**
     * @return array{date: string, present: int, absent: int, leave: int, total: int, present_percent: float|null}
     */
    private function attendanceCountsFor(Carbon $date): array
    {
        $present = Attendance::whereDate('date', $date)->where('status', 'present')->count();
        $absent = Attendance::whereDate('date', $date)->where('status', 'absent')->count();
        $leave = Attendance::whereDate('date', $date)->where('status', 'leave')->count();
        $total = $present + $absent + $leave;

        return [
            'date' => $date->toDateString(),
            'present' => $present,
            'absent' => $absent,
            'leave' => $leave,
            'total' => $total,
            'present_percent' => $total > 0 ? round($present / $total * 100, 1) : null,
        ];
    }

    /**
     * Dated (non-weekly) holidays only — a recurring Friday/Saturday row
     * isn't a meaningful "upcoming event" to list here, unlike a specific
     * government holiday.
     *
     * @return list<array{id: int, name: string, bn_name: ?string, start_date: string, end_date: string}>|null
     */
    private function upcomingHolidays(User $user): ?array
    {
        if (! $user->hasPermission('holidays.view')) {
            return null;
        }

        return Holiday::where('is_active', true)
            ->where('type', '!=', 'weekly')
            ->whereDate('start_date', '>=', Carbon::today())
            ->orderBy('start_date')
            ->limit(5)
            ->get()
            ->map(fn (Holiday $holiday) => [
                'id' => $holiday->id,
                'name' => $holiday->name,
                'bn_name' => $holiday->bn_name,
                'start_date' => $holiday->start_date->toDateString(),
                'end_date' => $holiday->end_date->toDateString(),
            ])
            ->all();
    }

    /**
     * `user_id` (not the profile's own id) is what the frontend's student
     * detail route (`/dashboard/students/:id`) actually expects — it
     * fetches by StudentUser id, same as StudentsPage's own list.
     *
     * @return list<array{user_id: int, student_id: ?string, name: ?string, school_name: ?string, admission_date: ?string}>|null
     */
    private function recentStudents(User $user): ?array
    {
        if (! $user->hasPermission('student_profiles.view')) {
            return null;
        }

        return StudentProfile::with(['user', 'school'])
            ->latest('created_at')
            ->limit(5)
            ->get()
            ->map(fn (StudentProfile $profile) => [
                'user_id' => $profile->user_id,
                'student_id' => $profile->student_id,
                'name' => $profile->user?->name,
                'school_name' => $profile->school?->name,
                'admission_date' => $profile->admission_date?->toDateString(),
            ])
            ->all();
    }
}
