# Teacher Attendance (Device Check-In) — Design

## Problem Statement

Teachers currently have no attendance record of their own at all. The platform already has a mature *student* attendance system (`Attendance`: one row per student per day, roster-based, recorded manually by the section's class teacher, holiday-aware, with monthly report/print already built in both the management and teacher portals) — but nothing tracks whether a *teacher* showed up.

The user wants:
1. Teachers to mark their own attendance via a physical attendance device, using either an ID card or facial recognition.
2. `school_admin` and `national_admin` to monitor attendance and generate/print attendance reports.
3. Teachers to view their own attendance records.
4. (Separately, in a later sub-project) students to mark their own attendance via ID card.

This design covers only item 1–3 (teacher attendance). Item 4 is a distinct sub-project — see Non-Goals.

## Goals

- A physical attendance device (make/model not yet chosen) identifies a teacher — by ID card scan or by its own built-in facial recognition — and reports that identification to this system as a timestamped "punch."
- `school_admin` (own school) and `national_admin` (every school) can see a monthly attendance grid per school and print it, mirroring the existing student `AttendanceReportPage`.
- A teacher can see their own check-in/check-out times and monthly status in the teacher portal, mirroring the existing student self-view pattern.
- `school_admin`/`national_admin` can manually correct a day's status (device offline, missed punch, etc.), mirroring `attendances.update`'s existing override convention for students.
- The device-integration layer (kiosk device tokens, the punch-ingestion endpoint) is built generically enough that the later student-attendance sub-project reuses it rather than needing its own redesign.
- Follows this codebase's existing conventions exactly: Auditable + SoftDeletes + TenantScoped models, Policy-based authorization, FormRequest-level `authorize()`, resource-computed `can_*` flags — same shape as `TeacherLeaveApplication`/`StudentLeaveApplication`.

## Non-Goals

- **No facial recognition built by this system.** The device performs the actual face (or ID card) match internally and reports the result (an identifier string) to us. We never receive, store, or process a photo/video frame, and we build no ML/matching code. (Confirmed with the user: "use attendance device" — a commercial biometric terminal, not in-browser recognition.)
- **No specific device/vendor integration.** No device has been chosen yet. We build a generic, documented HTTP endpoint any device (or a small bridge script sitting in front of one) can call; wiring an actual purchased device to call it is a separate, later piece of work outside this codebase. (Confirmed with the user.)
- **No "late" classification.** Status is present/absent/leave/holiday only. There is no existing concept of a school's expected start time to classify punctuality against, and inventing one wasn't asked for.
- **No student self-check-in in this round.** That is a separate sub-project, reusing the kiosk-device/token concept and the punch-ingestion endpoint's *shape*, but writing to its own tables. Not built here.
- **No device health/heartbeat monitoring, no device-type distinction** beyond a name on the `kiosk_devices` row — if punches stop arriving, that's visible simply as absent days, not as a dedicated "device offline" signal.
- **No notifications/alerts** (e.g. SMS to an admin when a teacher hasn't checked in by some time). Out of scope.
- **No portal-login check-in path.** Confirmed with the user: check-in happens at a shared device, not by a teacher logging into their own portal and clicking a button.

## Decision Log (confirmed with user during brainstorming)

1. Hardware approach → **a physical attendance device**, supporting face recognition and ID card for teachers, ID card only for (future) students. The device does the biometric matching; we only receive its result.
2. Check-in flow → **shared device**, not each person logging into their own portal.
3. Build order → **teacher attendance first**; student self-check-in is a separate, later sub-project.
4. Device integration protocol → **not yet decided / build device-agnostic.** No specific vendor chosen, so this design defines our own generic ingestion contract rather than assuming a push-webhook or pull/poll integration with a named product.

## Design

### 1. Data model

Three new tables.

**`kiosk_devices`** — one row per provisioned physical device:

```php
Schema::create('kiosk_devices', function (Blueprint $table) {
    $table->id();
    $table->nullableMorphs('created_by'); // Auditable — who provisioned it

    $table->foreignId('school_id')->constrained()->cascadeOnDelete();
    $table->string('name'); // e.g. "Front Gate Terminal"
    $table->string('token', 64)->unique(); // shown once at creation, hashed at rest — see §3
    $table->boolean('is_active')->default(true);
    $table->timestamp('last_used_at')->nullable();

    $table->timestamps();
    $table->softDeletes();
});
```

`App\Models\KioskDevice`: `Auditable, SoftDeletes, TenantScoped`. Revoking a device is a soft delete (`is_active` is a separate, explicit toggle for "temporarily disable without losing the row's history" — a school might want to pause a device without immediately deleting it).

**`teacher_attendances`** — one row per teacher per day, directly mirroring `Attendance`'s shape:

```php
Schema::create('teacher_attendances', function (Blueprint $table) {
    $table->id();
    $table->nullableMorphs('created_by');
    $table->nullableMorphs('updated_by');

    $table->foreignId('teacher_profile_id')->constrained()->cascadeOnDelete();
    $table->foreignId('school_id')->constrained()->cascadeOnDelete();
    $table->foreignId('district_id')->constrained()->cascadeOnDelete();

    $table->date('date');
    $table->string('status'); // present|absent|leave — holiday is computed, never stored (same convention as Attendance)
    $table->timestamp('check_in_at')->nullable();
    $table->timestamp('check_out_at')->nullable();

    // Set when school_admin/national_admin manually corrects a day —
    // distinguishes "the device recorded this" from "a human overrode it,"
    // same spirit as Attendance.marked_by_id identifying a human actor.
    $table->foreignId('corrected_by_id')->nullable()->constrained('users')->nullOnDelete();
    $table->text('correction_note')->nullable();

    $table->timestamps();
    $table->softDeletes();

    $table->unique(['teacher_profile_id', 'date']);
    $table->index(['school_id', 'date']);
});
```

`App\Models\TeacherAttendance`: `Auditable, SoftDeletes, TenantScoped`. `STATUSES = ['present', 'absent', 'leave']` (holiday is overlaid at read time from the `Holiday` calendar, exactly like `Attendance`/`AttendanceController::dateRange()` already does — never written as a stored status).

**`attendance_punches`** — immutable raw log, one row per punch, never summarized away (always an audit trail of every scan):

```php
Schema::create('attendance_punches', function (Blueprint $table) {
    $table->id();

    $table->foreignId('teacher_profile_id')->constrained()->cascadeOnDelete();
    $table->foreignId('kiosk_device_id')->nullable()->constrained()->nullOnDelete();
    $table->string('method'); // id_card|face
    $table->timestamp('punched_at');

    $table->timestamps();
    $table->index(['teacher_profile_id', 'punched_at']);
});
```

No `SoftDeletes`/`Auditable`/`TenantScoped` — this is a raw device log, not an admin-editable resource; `school_id` is reachable via `teacher_profile_id` when needed rather than denormalized, since nothing queries this table by school directly.

**Status derivation** (computed every time a punch lands — see §3 — and recomputed read-time wherever a holiday/leave overlay is needed, exactly mirroring `Attendance`'s existing holiday-overlay pattern):

- Any punch exists that day → **present**. `check_in_at` = earliest punch, `check_out_at` = latest punch (null if only one punch exists that day).
- No punch, but an *approved* `TeacherLeaveApplication` covers the date for that teacher → **leave** (reuses the exact `whereDate('from_date', '<=', $date)->whereDate('to_date', '>=', $date)` predicate `AttendancePolicy::isApprovedDelegateFor` already uses).
- No punch, but a `Holiday` covers the date → not stored at all; the report overlays the holiday name for that date the same way `AttendanceController::dateRange()` already does for students.
- Otherwise → **absent**. This is computed lazily at report-read time for *past* dates with no row (no need to pre-populate "absent" rows for every teacher every day) — a report builds its grid the same way `AttendanceController::mine()` does: iterate every date in range, look up a stored row, and fall back to absent/holiday/leave as above when none exists.

### 2. Kiosk device authentication

The punch-ingestion endpoint is called by an unauthenticated device, not a logged-in portal user, so it cannot sit behind the existing blanket `auth:sanctum` group. A new, narrow middleware (`App\Http\Middleware\AuthenticateKioskDevice`) does the equivalent job for a device instead of a user:

- Reads an `X-Kiosk-Token` header.
- Hashes it and looks up `KioskDevice::where('token', hash($token))->where('is_active', true)->first()`.
- 401s if missing/invalid/inactive.
- On success, binds the resolved `KioskDevice` onto the request (`$request->attributes->set('kiosk_device', $device)`) and touches `last_used_at`.

The token is generated once at device creation (`Str::random(48)` or similar), shown to the admin exactly once in the creation response (never again — only its hash is stored, same principle as a password), and the admin is responsible for entering it into the physical device's own configuration.

`school_id` for every punch is **always** resolved from the authenticated device, never trusted from the request body — the same "never trust client-supplied tenant scope" principle this codebase already applies everywhere (e.g. `StudentProfileController::store()`'s server-generated `student_id`).

### 3. API surface

**Unauthenticated (kiosk-token-protected, registered outside the `auth:sanctum` group, alongside this app's other intentionally-public endpoints like `practice-exams`):**

```
POST /attendance-punches
```
Body: `{ identifier: string, method: 'id_card'|'face', captured_at?: ISO8601 }` (`captured_at` optional — defaults to request-received time if the device doesn't supply its own clock).

Handling:
1. Resolve the authenticated `KioskDevice` (via the middleware) → its `school_id`.
2. Look up `TeacherProfile::where('school_id', $device->school_id)->where('employee_id', $identifier)->first()`. Not found → 404 with a generic "unrecognized ID" message (no information disclosure about which IDs *do* exist).
3. Insert an `AttendancePunch` row.
4. Upsert the day's `TeacherAttendance` row: `status = 'present'`, `check_in_at` = min(existing, this punch), `check_out_at` = max(existing, this punch) when a second punch lands the same day.
5. Return 201 with just `{ name, punched_at }` — enough for the device to show a confirmation ("John Doe checked in at 9:02 AM"), nothing more.

This contract only resolves against `TeacherProfile` today. The student sub-project adds a second lookup branch (falling back to `StudentProfile.student_id`) and its own summary table — the URL, auth mechanism, and request/response shape do not need to change.

**Authenticated (existing `auth:sanctum` group, mirroring `attendances.*`'s existing route shape):**

```
GET    /kiosk-devices                       — list (school_admin: own school via TenantScope; national_admin: every school)
POST   /kiosk-devices                       — create (returns the plaintext token once)
PATCH  /kiosk-devices/{kiosk_device}        — rename / toggle is_active
DELETE /kiosk-devices/{kiosk_device}        — soft delete

GET    /teacher-attendances/report          — monthly grid for a school (params: school_id, year, month)
GET    /teacher-attendances/mine            — the authenticated teacher's own monthly record
PATCH  /teacher-attendances/correct         — manual correction (school_admin/national_admin only) —
                                               body: { teacher_profile_id, date, status, correction_note? }
```

**Authorization:**
- `kiosk-devices.*` → new permissions `kiosk_devices.view`, `.create`, `.update`, `.delete`. `national_admin` via wildcard; `school_admin` explicit, in `RolePermissionSeeder`.
- `teacher-attendances/report` → new permission `teacher_attendances.view`, same grant pattern.
- `teacher-attendances/{date}` (correction) → new permission `teacher_attendances.update`, same grant pattern.
- `teacher-attendances/mine` → `authorize()` just checks `$this->user() instanceof TeacherUser`, exactly like `TeacherLeaveApplicationController::mine()` — self-service bypass, no permission needed.

**New permissions** added to `PermissionSeeder`, granted to `school_admin` in `RolePermissionSeeder` (`national_admin` already has everything): `kiosk_devices.view`, `kiosk_devices.create`, `kiosk_devices.update`, `kiosk_devices.delete`, `teacher_attendances.view`, `teacher_attendances.update`.

**Policies:** `KioskDevicePolicy` (viewAny/view/create/update/delete, all `hasPermission(...)`, `TenantScoped` narrows `school_admin` automatically — same shape as `SchoolPolicy`). `TeacherAttendancePolicy` (`viewAny`/`view` → `teacher_attendances.view`; `update` → `teacher_attendances.update`; a dedicated `viewMine`/implicit self-check in the controller for `mine()`, same convention as `AttendanceController::mine()` which has no policy call at all, just an `instanceof` guard).

### 4. Frontend

**Management portal:**
- **Kiosk Devices** page — a `GeoCrudTable`-shaped admin page (create shows the plaintext token once in a dismissable banner/modal, exactly like e.g. an API-key-creation UX elsewhere; list shows name, school, active toggle, last used; delete with the existing confirm-dialog convention). Gated via `createPermission`/`updatePermission`/`deletePermission` props, same as every other page this cycle.
- **Teacher Attendance Report** page — mirrors `AttendanceReportPage.tsx` almost exactly: School → Month filters (no section cascade needed — this is school-wide, not per-section), a printable monthly grid (rows = teachers, columns = days, P/A/L letters, holiday columns shaded), `window.print()` button, same print CSS convention as every other print page in this app.

**Teacher portal:**
- **My Attendance** page mirroring the student portal's own attendance self-view: a monthly calendar/summary showing status per day plus check-in/check-out times for present days, read-only (no record/edit controls — there is nothing for a teacher to self-report, the device is the only source of truth, with `school_admin` as the only override path).

Nav placement in both portals is an implementation-time detail, not a design-level decision (same note as the teacher-leave-applications spec).

### 5. Testing strategy

- **Permission-mapping drift guard**: `kiosk_devices.*` and `teacher_attendances.*` map to exactly `['national_admin', 'school_admin']`.
- **Kiosk auth middleware**: valid token succeeds and touches `last_used_at`; missing/invalid/inactive token 401s; a token from School A's device cannot resolve a teacher from School B (cross-tenant isolation — punches are scoped by the device's own `school_id`, never request input).
- **Punch ingestion**: unknown identifier 404s without leaking valid-ID information; first punch of the day creates a `present` `TeacherAttendance` row with `check_in_at` set and `check_out_at` null; a second same-day punch updates `check_out_at` without touching `check_in_at`; a punch for a teacher on *approved* leave still records as present (a punch is hard evidence of actual attendance and should always win over a leave record — explicit test since this is the one place a future maintainer might assume leave should take precedence).
- **Report derivation**: a day with no punch and no leave/holiday reads as absent; a day covered by `Holiday` never reads as absent even with no row; a day covered by approved leave with no punch reads as leave.
- **Authorization**: `school_admin` sees only their own school's devices/report; `national_admin` sees every school; a teacher actor gets 403 on `kiosk-devices.*` and `teacher-attendances/report`, but 200 on their own `mine`.
- **Manual correction**: `school_admin`/`national_admin` can overwrite a day's status with a note; a teacher actor gets 403.

## Risks

- **Identifier collisions across schools**: `employee_id` is looked up scoped to the authenticated device's own `school_id` (never globally), so two different schools using the same `employee_id` scheme (unlikely given `TeacherProfile::nextStudentId()`-style server-generated IDs, but not structurally guaranteed) cannot cross-resolve to the wrong teacher. Worth an explicit cross-school test (see §5), same reasoning as the teacher-leave-applications spec's `colleagues` endpoint risk.
- **A lost/stolen device token** can record punches for any teacher at that school until revoked. Mitigated by the "soft-delete / `is_active` toggle" revocation path being immediate and by the token never being displayed again after creation (so a compromised token is only ever the one actively configured on the physical device, not retrievable from our own UI later). No additional mitigation (e.g. IP allowlisting) is in scope here — can be layered on later if needed.
- **No device has been chosen yet**, so the exact request shape (`identifier`/`method`/`captured_at`) is an assumption about what a typical device/bridge can send, not a verified integration. This is expected to need a small adjustment once a real device is selected and wired up — the kiosk-auth and data-model layers underneath should not need to change, only the exact field mapping in `AttendancePunchController::store()`.
