# Teacher Leave Applications & Substitute Delegation — Design

## Problem Statement

Teachers currently have no self-service way to request leave. The platform already has an analogous flow for students (`StudentLeaveApplication`: a student applies, their section's class teacher reviews), but nothing equivalent exists for teachers themselves.

The user wants:
1. A teacher to submit a leave request through the teacher portal.
2. `school_admin` to approve or reject it.
3. While on leave, the teacher to be able to delegate their attendance-recording duty — for whichever section(s) they are the homeroom (`class_teacher_id`) of — to another teacher at the same school.
4. That delegate teacher to actually be able to record attendance for those sections during the leave window, which today is hard-gated to `Section.class_teacher_id === the acting teacher`.
5. `national_admin` to see which teachers are currently on leave, read-only, across every school.

This closes the loop the previous session opened: the teacher portal's "Leave Applications" menu was renamed to "Student Leave" specifically to make room for a parallel "Teacher Leave" menu (see `14aab8b`).

## Goals

- A teacher can submit a leave request (date range + reason) and optionally name a substitute colleague at their own school.
- `school_admin` can list and review (approve/reject, with an optional comment) leave requests for their own school's teachers only.
- Once approved, the named substitute (if any) gains the ability to record attendance for every section the applicant is `class_teacher_id` of, for exactly the leave's date range — automatically, with no separate activation step and no separate acceptance step for the substitute.
- The moment the leave's `to_date` passes (or the request is rejected/never approved), the delegated ability disappears on its own — no cleanup job.
- `national_admin` sees a read-only list of every teacher currently on approved leave, across every school: name, school, dates, substitute (if any).
- Follows this codebase's existing conventions exactly: Auditable + SoftDeletes + TenantScoped model, Policy-based authorization (no route-level `permission:` middleware anywhere in this app), FormRequest-level `authorize()`, resource-computed `can_*` flags instead of client-side permission re-derivation.

## Non-Goals

- **No delegation of other class-teacher duties.** Recording exam marks (`AcademicExamMarkPolicy`) and reviewing that section's *student* leave applications (`StudentLeaveApplicationPolicy::review`) both also gate on `class_teacher_id` today — neither changes. During a teacher's leave, those two abilities simply have no one who can exercise them until the teacher returns. (Confirmed explicitly with the user — see decision log below.)
- **No per-section substitute granularity.** One named substitute covers every section the applicant is homeroom teacher for, for the whole leave. A teacher who is `class_teacher_id` of more than one section cannot name a different substitute per section. (Confirmed with the user; this is expected to be the rare case in practice.)
- **No substitute acceptance step.** The named substitute does not need to confirm anything; the delegation is automatically in effect the moment `school_admin` approves the leave. (Confirmed with the user.)
- **No cancel/withdraw endpoint.** A teacher cannot retract a submitted request. If the dates are wrong, `school_admin` rejects it and the teacher resubmits — this exactly mirrors `StudentLeaveApplication`, which also has no cancel path.
- **No `national_admin` approve/reject capability.** Reviewing stays exclusively a `school_admin` action; `national_admin` is read-only for this resource. (Confirmed with the user.)
- **No overlap check against the delegate's own leave.** If the named substitute is themselves on approved leave during the same window, nothing stops it server-side; `school_admin` will see both requests when reviewing and can reject accordingly. Deliberately left unhandled rather than adding speculative logic.
- **No delete/destroy endpoint** for the resource — matches `StudentLeaveApplication`, which also has none.

## Decision Log (confirmed with user during brainstorming)

1. Delegation scope → **attendance recording only**.
2. Delegation granularity → **one substitute for the whole leave**.
3. Workflow timing → **substitute named at submission; takes effect automatically on approval; no acceptance step**.
4. `national_admin` capability → **read-only list only**.

## Design

### 1. Data model

New table `teacher_leave_applications`, modeled directly on `student_leave_applications` (same traits, same shape where it applies):

```php
Schema::create('teacher_leave_applications', function (Blueprint $table) {
    $table->id();

    // Auditable (polymorphic, no DB FK — matches every other Auditable table)
    $table->nullableMorphs('created_by');
    $table->nullableMorphs('updated_by');

    $table->foreignId('teacher_id')->constrained('teacher_users')->cascadeOnDelete();
    $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
    $table->foreignId('district_id')->nullable()->constrained('districts')->nullOnDelete();

    $table->date('from_date');
    $table->date('to_date');
    $table->text('reason');

    $table->string('status')->default('pending'); // pending|approved|rejected

    // Nullable: a teacher with no homeroom section has nothing to delegate.
    $table->foreignId('delegate_teacher_id')->nullable()->constrained('teacher_users')->nullOnDelete();

    $table->text('admin_comment')->nullable();
    $table->foreignId('reviewed_by_id')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamp('reviewed_at')->nullable();

    $table->timestamps();
    $table->softDeletes();

    $table->index(['school_id', 'status']);
    $table->index(['teacher_id', 'status']);
    $table->index(['status', 'from_date', 'to_date']); // the "currently on leave" query
});
```

Key differences from the `student_leave_applications` template, both deliberate:
- `reviewed_by_id` points to **`users`**, not `teacher_users` — the reviewer is always `school_admin` (a `User`), never a teacher. `StudentLeaveApplication`'s reviewer is a `TeacherUser` because that resource's reviewer *is* a teacher (the class teacher).
- No `section_id`/`class_id`/`shift_id`/`class_version_id` columns at all. `StudentLeaveApplication` denormalizes those because a student's own section is fixed data needed to route the review to the right class teacher. Here, "which sections does this affect" is never stored — it's computed live as "every section where `class_teacher_id = teacher_id`" at authorization-check time (see §2). This means the delegation stays correct even if the applicant's homeroom assignment changes after submission, with no sync needed.

`App\Models\TeacherLeaveApplication`: `Auditable, SoftDeletes, TenantScoped` (identical trait set to every other tenant resource); `STATUSES = ['pending', 'approved', 'rejected']`; relations `teacher()`, `delegate()` → both `TeacherUser`; `reviewedBy()` → `User`; casts `from_date`/`to_date` to `date`, `reviewed_at` to `datetime`.

### 2. Authorization — granting the delegate actual recording rights

`AttendancePolicy` gains one additional OR-branch in both `viewSection()` and `recordSection()`:

```php
private function isApprovedDelegateFor(TeacherUser $user, Section $section): bool
{
    return TeacherLeaveApplication::query()
        ->where('teacher_id', $section->class_teacher_id)
        ->where('delegate_teacher_id', $user->id)
        ->where('status', 'approved')
        ->whereDate('from_date', '<=', now())
        ->whereDate('to_date', '>=', now())
        ->exists();
}

public function recordSection(Authenticatable $user, Section $section): bool
{
    if ($user instanceof TeacherUser) {
        return $section->class_teacher_id === $user->id
            || $this->isApprovedDelegateFor($user, $section);
    }
    return $user->hasPermission('attendances.update');
}
```

(`viewSection()` gets the same extra clause so the substitute can see the roster/history, not just blindly post attendance.)

This is evaluated fresh on every request — no "activate"/"expire" job, no status flag beyond `approved`. The exact same date-range-and-status predicate is the "currently on leave" query reused by the `national_admin` view (§4).

`AttendanceController::mySections()` (the list a teacher picks a section from) gets the matching addition: sections delegated to the acting teacher are included in the list with `can_record: true`, so the substitute can actually find the section, not just pass a policy check if they already knew the ID.

Nothing else changes. `AcademicExamMarkPolicy` and `StudentLeaveApplicationPolicy::review()` keep their existing `class_teacher_id`-only checks, per the Non-Goals above.

### 3. API surface

All routes sit inside the existing blanket `auth:sanctum` group (this app has no `permission:` route middleware anywhere — every decision is a Policy/FormRequest call):

```
POST   /teacher-leave-applications                — submit
GET    /teacher-leave-applications/mine           — own applications (must be registered before the {id} show route)
GET    /teacher-leave-applications/colleagues     — NEW: {id, name} of other teachers at my own school, for the substitute picker
GET    /teacher-leave-applications                — list (school_admin / national_admin)
GET    /teacher-leave-applications/{id}
PATCH  /teacher-leave-applications/{id}/review     — approve/reject
```

**Authorization per endpoint:**
- `store`, `mine`, `colleagues`: `authorize()` just checks `$this->user() instanceof TeacherUser` — bypasses the permission system entirely, exactly like `StoreStudentLeaveApplicationRequest`. `colleagues` additionally relies on `TeacherUser`'s own `TenantScope` to narrow the query to the caller's own school — no explicit `school_id` filter needed in the controller.
- `index`, `show`: `TeacherLeaveApplicationPolicy::viewAny`/`view` → `hasPermission('teacher_leave_applications.view')`. `national_admin` gets this for free via its all-permissions wildcard; `school_admin` needs it granted explicitly. `TenantScope` on the model narrows `school_admin`'s results to their own school automatically — no per-row ownership check is needed here (unlike `StudentLeaveApplicationPolicy`, which needs an inline `class_teacher_id` check because *multiple* class teachers share one school; here there is exactly one reviewer role per school).
- `review`: `TeacherLeaveApplicationPolicy::review` → `hasPermission('teacher_leave_applications.update')`.

**New permissions** (added to `PermissionSeeder`): `teacher_leave_applications.view`, `teacher_leave_applications.update`. Granted to `school_admin` only in `RolePermissionSeeder` (`national_admin` already has everything; `teacher` needs neither — submission/mine/colleagues are all self-service bypass).

**Validation** (`StoreTeacherLeaveApplicationRequest`):
- `from_date` required date; `to_date` required date, `after_or_equal:from_date`; `reason` required string max 2000 (mirrors the student-side limits).
- `delegate_teacher_id` nullable; when present, `Rule::exists('teacher_users', 'id')` plus a custom rule rejecting `delegate_teacher_id === $this->user()->id` and rejecting a delegate not in the applicant's own school (resolved via `TeacherUser::find($id)?->school_id === $this->user()->school_id` — the applicant's own `school_id`, not trusted from the request body).
- A new request overlapping (`from_date`/`to_date`) an existing `pending` or `approved` application for the same `teacher_id` is rejected (422) — prevents two conflicting delegations/requests coexisting.

`ReviewTeacherLeaveApplicationRequest` mirrors `ReviewStudentLeaveApplicationRequest` exactly: `status` required, `Rule::in(['approved', 'rejected'])` (review can only move forward from `pending`), `admin_comment` nullable string max 2000.

**Resource** (`TeacherLeaveApplicationResource`): exposes `can_review` (computed server-side, same convention as `StudentLeaveApplicationResource::can_review`), plus nested `teacher`, `delegate`, `reviewed_by` summaries and the raw `status`/dates/comment fields.

### 4. Frontend

**Teacher portal** — new "Teacher Leave" nav item (parallel to the just-renamed "Student Leave"), route `/teacher-leave`, two views on one page:
- "Submit a request": date-range inputs, reason textarea, an optional substitute `SearchableSelect` sourced from `GET /teacher-leave-applications/colleagues`.
- "My requests": a read-only status list (pending/approved/rejected, with `admin_comment` shown once reviewed) — no edit/cancel action, per Non-Goals.

**Management portal, `school_admin`** — new page listing teacher leave applications for their own school (TenantScope-filtered automatically), with an approve/reject action + optional comment, following the same list-plus-review-modal shape as every other `GeoCrudTable`-adjacent page in this app. Gated via `createPermission`/`updatePermission` props exactly like every other page added this cycle — though since there's no create-by-admin path at all here (only the teacher creates their own), only the review action needs gating via `can('teacher_leave_applications.update')`.

**Management portal, `national_admin`** — a read-only "Teachers on Leave" view: every `approved` application where `today BETWEEN from_date AND to_date`, across every school (no school filter needed day one, but trivial to add). Columns: teacher, school, dates, substitute. No actions.

Both new management-portal pages sit under a nav location to be decided at implementation time (most naturally alongside the existing Teachers/attendance-adjacent items) — not a design-level decision, just a placement detail.

### 5. Testing strategy

- **Policy-mapping drift guard** (same style as `UsersRolesPermissionMappingTest`): `teacher_leave_applications.view`/`.update` map to exactly `['national_admin', 'school_admin']`.
- **Feature tests on the controller**: store (success + validation failures: bad date order, self-delegation, cross-school delegate, overlapping existing request), `mine` (self-scoped), `colleagues` (same-school only, excludes self, and empty for a teacher with no school assigned), `index`/`show` (school_admin sees only their school; national_admin sees everything; a teacher actor gets 403), `review` (school_admin approves/rejects; a teacher actor gets 403; re-reviewing an already-decided row is allowed to proceed and change the status — `review()` has no guard against re-deciding an already-decided application, matching `StudentLeaveApplicationController::review()`'s own identical behavior; this is a deliberate consistency choice with the established convention for the analogous resource, not an oversight).
- **The regression test this feature is actually about**: recording attendance as the named substitute succeeds for the applicant's section while `status=approved` and `today` is within range; fails before approval, after `to_date`, and for a teacher who isn't the named delegate. Mirror this for `viewSection`.
- **Non-regression**: an ordinary class teacher (not on leave, no delegation involved) still records their own section exactly as before; `AcademicExamMarkPolicy`/`StudentLeaveApplicationPolicy::review` are unaffected by an active delegation (explicit test, since this is the one place a future maintainer might assume delegation should "just work" everywhere `class_teacher_id` appears).

## Risks

- The `recordSection`/`viewSection` delegate check runs a query (`TeacherLeaveApplication::query()->where(...)->exists()`) on every attendance view/record call for every teacher, not just ones on leave. This is a single indexed lookup (`['status', 'from_date', 'to_date']` composite covers it) and matches the cost profile of the existing `SubjectTeacherAssignment::where(...)->exists()` check already in the same method — not expected to be a measurable regression, but worth confirming with the existing attendance test suite's timing if it's ever a concern.
- `colleagues` is a new permission-bypass endpoint (`instanceof TeacherUser` only, like `store`/`mine`) — it must only ever return `{id, name}`, never anything more (no email/phone/school details), since it's reachable by any teacher with no explicit permission check, and must rely on `TenantScope` actually narrowing results (verify with a cross-school test, not just assumed).
