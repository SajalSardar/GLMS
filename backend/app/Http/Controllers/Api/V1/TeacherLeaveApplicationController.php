<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ReviewTeacherLeaveApplicationRequest;
use App\Http\Requests\Api\V1\StoreTeacherLeaveApplicationRequest;
use App\Http\Resources\Api\V1\TeacherLeaveApplicationResource;
use App\Models\TeacherLeaveApplication;
use App\Models\TeacherUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class TeacherLeaveApplicationController extends Controller
{
    private const WITH = ['teacher.school', 'delegate', 'reviewedBy'];

    /**
     * Staff listing, scoped by TenantScope (school/district) plus the
     * teacher_leave_applications.view permission. `current=1` narrows to
     * applications in effect right now (approved, today within range) —
     * the "who is on leave right now" view national_admin uses; omitted,
     * every status/date is returned.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', TeacherLeaveApplication::class);

        $applications = TeacherLeaveApplication::query()
            ->with(self::WITH)
            ->when($request->string('status')->toString(), fn ($query, $status) => $query->where('status', $status))
            ->when($request->boolean('current'), fn ($query) => $query
                ->where('status', 'approved')
                ->whereDate('from_date', '<=', now())
                ->whereDate('to_date', '>=', now()))
            ->orderByDesc('created_at')
            ->get();

        return TeacherLeaveApplicationResource::collection($applications);
    }

    public function show(TeacherLeaveApplication $teacher_leave_application): TeacherLeaveApplicationResource
    {
        $this->authorize('view', $teacher_leave_application);

        return new TeacherLeaveApplicationResource($teacher_leave_application->load(self::WITH));
    }

    /**
     * A teacher submits their own leave application, scoped server-side
     * to their own school/district (never taken from the request) — same
     * reasoning as StudentLeaveApplicationController::store(). A teacher
     * with no school assigned is rejected outright: school_id is NOT
     * NULL on teacher_leave_applications, so letting this through would
     * otherwise surface as an uncaught DB constraint violation (500)
     * instead of a clean validation error — same guard shape as
     * StudentLeaveApplicationController::store()'s profile check.
     */
    public function store(StoreTeacherLeaveApplicationRequest $request): JsonResponse
    {
        $teacher = $request->user();

        if ($teacher->school_id === null) {
            throw ValidationException::withMessages([
                'teacher' => ['Your account has no school assigned. Please contact your administrator.'],
            ]);
        }

        $application = TeacherLeaveApplication::create([
            'teacher_id' => $teacher->id,
            'school_id' => $teacher->school_id,
            'district_id' => $teacher->district_id,
            'from_date' => $request->input('from_date'),
            'to_date' => $request->input('to_date'),
            'reason' => $request->input('reason'),
            'status' => 'pending',
            'delegate_teacher_id' => $request->input('delegate_teacher_id'),
        ]);

        return (new TeacherLeaveApplicationResource($application->load(self::WITH)))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * This teacher's own leave applications, most recent first.
     */
    public function mine(Request $request): AnonymousResourceCollection
    {
        $teacher = $request->user();
        abort_unless($teacher instanceof TeacherUser, 403);

        $applications = TeacherLeaveApplication::query()
            ->with(self::WITH)
            ->where('teacher_id', $teacher->id)
            ->orderByDesc('created_at')
            ->get();

        return TeacherLeaveApplicationResource::collection($applications);
    }

    /**
     * Other teachers at this teacher's own school, for the substitute
     * picker — self-service, bypasses the permission system entirely
     * (same convention as store()/mine()). The school_id filter is
     * explicit here rather than left to TenantScope: TenantScope falls
     * through to completely unrestricted once BOTH school_id and
     * district_id are null on the acting user, which a TeacherUser with
     * no school assigned is a real, reachable state for (see
     * test_store_rejects_a_teacher_with_no_school_assigned) — relying on
     * it alone would hand that teacher every teacher nationwide instead
     * of an empty list. Deliberately minimal (id + name only) — this
     * endpoint has no permission check at all, so it must never leak
     * anything beyond a name.
     */
    public function colleagues(Request $request): JsonResponse
    {
        $teacher = $request->user();
        abort_unless($teacher instanceof TeacherUser, 403);

        $colleagues = TeacherUser::query()
            ->where('id', '!=', $teacher->id)
            ->where('school_id', $teacher->school_id)
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json(['data' => $colleagues]);
    }

    /**
     * Approve or reject, with an optional comment. The reviewer here is
     * always a staff User (only school_admin/national_admin ever hold
     * teacher_leave_applications.update — see RolePermissionSeeder), so
     * reviewed_by_id is stamped unconditionally — unlike
     * StudentLeaveApplicationController::review(), where the reviewer can
     * also be a TeacherUser.
     */
    public function review(ReviewTeacherLeaveApplicationRequest $request, TeacherLeaveApplication $teacher_leave_application): TeacherLeaveApplicationResource
    {
        $teacher_leave_application->update([
            'status' => $request->input('status'),
            'admin_comment' => $request->input('admin_comment'),
            'reviewed_by_id' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        return new TeacherLeaveApplicationResource($teacher_leave_application->load(self::WITH));
    }
}
