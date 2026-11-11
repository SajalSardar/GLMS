# Design: Split `users` by Role (Student/Teacher/Guardian Login Tables)

**Status:** Approved, ready for implementation planning

**Source:** Direct request — staff accounts (national_admin, district_officer,
school_admin, office_staff, it_admin, auditor, trainer) stay in the existing
`users` table; student, teacher, and parent_guardian accounts move to three
new tables (`student_users`, `teacher_users`, `guardian_users`), each storing
their own login credentials directly rather than sharing `users`. The login
API is updated to match.

## Why

Today every role — staff and student/teacher/guardian alike — shares one
`users` table. The request is to separate login/account storage for
student, teacher, and guardian from the staff-only `users` table, giving
each its own table.

## Scope of this project

**In scope:**

- Three new tables (`student_users`, `teacher_users`, `guardian_users`),
  each mirroring `users`' column shape exactly.
- A one-time data migration moving existing `student`/`teacher`/
  `parent_guardian` rows out of `users` into the matching new table, and
  repointing `student_profiles.user_id` / `teacher_profiles.user_id` /
  `guardians.user_id` to the new tables.
- New Eloquent models `StudentUser`, `TeacherUser`, `GuardianUser`.
- `AuthController`'s `teacherLogin()`/`studentLogin()` repointed to the new
  tables; a new `guardianLogin()` added for symmetry. `login()` and
  `managementLogin()` are unchanged (`users` only).
- Three new account-creation endpoints (`teacher-users`, `student-users`,
  `guardian-users`), each with its own Controller, Policy, `Store*Request`/
  `Update*Request`, mirroring `UserController`/`UserPolicy` but with a fixed
  role (no caller-supplied `role_id`).
- `StoreTeacherProfileRequest`/`StoreStudentProfileRequest`/
  `StoreGuardianRequest`'s `user_id` validation repointed from
  `Rule::exists('users', 'id')` to the matching new table.
- Every Policy's first-parameter type-hint loosened from `App\Models\User`
  to `Illuminate\Contracts\Auth\Authenticatable`, since the authenticated
  actor can now be one of four model classes. `Guardian::scopeVisibleTo()`/
  `isVisibleTo()` get the same treatment (their `$actor` parameter).
- `created_by`/`updated_by` on the 25 audited tables (from the prior
  audit-columns project) converted from a flat FK-to-`users.id` pair into a
  polymorphic `{type}_type`/`{type}_id` pair, with the `Auditable` trait and
  all 25 `*Resource` classes updated to match. Existing rows backfilled with
  `created_by_type = updated_by_type = 'App\\Models\\User'` (every row
  created so far was created by a staff actor).
- Factories for the three new models, and new/updated test-helper actor
  builders in `tests/Concerns/CreatesAuthorizedActors.php`.
- Seeders that create teacher/student/guardian demo accounts, repointed to
  the new tables.
- **Every other FK pointing at `users.id` that actually means "a
  teacher"/"a student" specifically, found by auditing every
  `constrained('users')`/`foreignId('user_id')` in the migrations
  directory** (see "Domain FKs beyond the profile tables" below):
  `class_routines.teacher_id`, `sections.class_teacher_id`,
  `subject_teacher_assignments.teacher_id`, `timetable_entries.teacher_id`
  (all → `teacher_users`), and `exam_attempts.user_id` (→ `student_users`).
  Their model relations (`ClassRoutine::teacher()`, `Section::classTeacher()`,
  `SubjectTeacherAssignment::teacher()`, `TimetableEntry::teacher()`,
  `ExamAttempt::user()`) repoint accordingly, as do the `Rule::exists('users',
  'id')` validations in `StoreClassRoutineRequest`, `StoreSectionRequest`,
  `StoreSubjectTeacherAssignmentRequest`, `StoreTimetableEntryRequest`.
- **Two more columns that need the same polymorphic treatment as
  `created_by`/`updated_by`**, because — unlike the FKs above — the actor
  genuinely can be any of the four tables:
  `content_access_logs.user_id` (any authenticated role can view/download a
  book or chapter material) and `chapter_video_progress.user_id`
  (`UpdateChapterVideoProgressRequest::authorize()`: "Any authenticated
  user may report their own progress"). Both become `user_type`/`user_id`
  morph columns, same shape and same backfill rule
  (`user_type = App\Models\User::class` wherever the existing value is
  non-null) as `created_by`/`updated_by`. `ChapterVideoProgress::user()`
  and `ContentAccessLog::user()` become `morphTo('user')`.

**Explicitly out of scope:**

- No new Sanctum guards or providers — Sanctum's `personal_access_tokens`
  table is already polymorphic (`tokenable_type`/`tokenable_id`), so a
  token issued to any of the four models resolves through
  `$request->user()` without any guard/provider changes.
- No change to the Role/Permission system itself — `role_id` and
  `hasPermission()` keep working exactly as they do today, just on four
  model classes instead of one. New Policies reuse the existing permission
  strings (`users.view`/`users.create`/`users.update`/`users.delete`) rather
  than inventing new ones, matching today's behavior where a school_admin's
  `users.create`/`users.update` already lets them create a teacher or
  student account.
- No frontend work for a guardian portal — `guardianLogin()` is added for
  backend symmetry with teacher/student, but nothing in `frontend/` consumes
  it yet.
- No cross-table email/phone uniqueness. Each new table has its own unique
  constraint on `email`/`phone`, same as `users` has today, but nothing
  prevents the same email existing once in `users` and once in
  `teacher_users` — there is no single table left to put a cross-table
  constraint on, and faking one with triggers/app-level cross-checks is not
  part of this project.
- No `created_by`/`updated_by` audit columns on the three new login tables
  themselves — they are accounts, not resources in the audited-25 sense.
- No change to `PermissionController`/`RoleController` or the
  `role_permissions` pivot.

## Decisions confirmed during brainstorming

- **Full `users`-table shape, duplicated per table.** `student_users`,
  `teacher_users`, `guardian_users` all get `role_id`, `school_id`,
  `district_id`, `name`, `email`, `phone`, `password`, soft-deletes,
  timestamps — identical to `users` today. `guardian_users.school_id`/
  `district_id` stay nullable and unused in practice (a `Guardian` isn't
  tied to one school today either — see `Guardian::scopeVisibleTo()`,
  which derives visibility from the guardian's linked students, not a
  school_id column). This means `TenantScope` and every Policy keep working
  completely unchanged: the authenticated model still has `school_id`,
  `district_id`, and `hasPermission()` in exactly the shape they expect.
- **Profile tables stay as-is, just repoint their FK.** `student_profiles`,
  `teacher_profiles`, `guardians` are untouched except for `user_id` now
  referencing the new tables. No merging of login fields into profile
  tables, no merging of profile fields into the login tables.
- **Policy type-hints loosen to `Authenticatable`, not a custom interface.**
  This codebase has no PHPStan/Larastan, so there is no static-analysis
  benefit to a custom interface declaring `hasPermission()` — PHP only
  checks that the concrete object has the method at call time, not that
  the type-hint's interface declares it. Loosening to Laravel's own
  `Illuminate\Contracts\Auth\Authenticatable` (which all four models
  already implement) is the minimal, mechanical fix: ~27 Policy files each
  get their `User $user`/`User $actor` parameters changed to
  `Authenticatable $user`/`Authenticatable $actor`, method bodies
  unchanged.
- **`created_by`/`updated_by` go polymorphic, not left null for non-staff
  actors.** Teachers already create `questions`/`exams` today
  (`questions.create`, `exams.create` in `RolePermissionSeeder`). Leaving
  those columns null whenever the actor isn't a `users` row would mean the
  audit trail silently loses attribution for a large fraction of real
  actors. The correct fix — `{column}_type` + `{column}_id`, no DB-level FK
  (can't FK across four possible tables) — costs a second migration
  touching the same 25 tables, but is the only option that keeps the audit
  trail meaningful once teachers are a separate model.
- **New account-creation endpoints, not a merged create-profile-and-account
  flow.** The existing two-step pattern (create the login row via
  `POST /users`, then attach a profile via `user_id`) is preserved exactly
  — `POST /teacher-users` (etc.) replaces the first step for its role, the
  second step (`POST /teacher-profiles`) is unchanged except for which
  table `user_id` is validated against. This avoids reshaping
  `StoreTeacherProfileRequest`/`StoreStudentProfileRequest`/
  `StoreGuardianRequest` or their controllers' transaction boundaries.

## Data model

### New tables

`student_users`, `teacher_users`, `guardian_users` — each:

```
id, role_id (FK roles, nullable), school_id (FK schools, nullable),
district_id (FK districts, nullable), name, email (nullable, unique),
phone (nullable, unique), password, remember_token, email_verified_at,
created_at, updated_at, deleted_at
```

(Exactly `users`' current columns, minus nothing, plus nothing.)

### Data migration (one-time, run once in `up()`)

For each of the three roles (`student` → `student_users`, `teacher` →
`teacher_users`, `parent_guardian` → `guardian_users`):

1. Copy every `users` row with that `role_id` into the matching new table,
   preserving `id` (so `student_profiles.user_id` etc. keep pointing at the
   right row without a remap table).
2. Update `student_profiles.user_id` / `teacher_profiles.user_id` /
   `guardians.user_id` — no change needed to the *value*, only to the
   column's FK target, since `id` was preserved.
3. Delete the migrated rows from `users`.

`down()` reverses this: copy rows back into `users`, delete from the new
tables. Given this moves real data, it runs inside a transaction per role,
and is written so re-running it after a partial failure doesn't duplicate
rows (check `WHERE NOT EXISTS` before each insert, matching the
idempotency pattern from the audit-columns migration).

### `created_by`/`updated_by` polymorphism

On all 25 tables from the audit-columns project, `created_by`/`updated_by`
(nullable `unsignedBigInteger`, FK to `users.id`) become
`created_by_type`/`created_by_id` and `updated_by_type`/`updated_by_id`
(nullable `string`/`unsignedBigInteger` pair, no FK). Existing rows get
`created_by_type = updated_by_type = User::class` (the FQCN string Eloquent
morph columns conventionally store) wherever `created_by_id`/`updated_by_id`
is non-null; both stay null where the existing value was already null.

### Domain FKs beyond the profile tables

Auditing every migration that constrains a column to `users` turned up more
than the three profile tables. Classified by checking who actually holds
the relevant permission in `RolePermissionSeeder` (not by guessing from the
column name):

| Table.column | Who can actually be it | Treatment |
|---|---|---|
| `class_routines.teacher_id` | only ever a teacher (domain reference: which teacher, not who created the row) | repoint FK to `teacher_users` |
| `sections.class_teacher_id` | only ever a teacher | repoint FK to `teacher_users` |
| `subject_teacher_assignments.teacher_id` | only ever a teacher | repoint FK to `teacher_users` |
| `timetable_entries.teacher_id` | only ever a teacher | repoint FK to `teacher_users` |
| `exam_attempts.user_id` | only `student` role has `exam_attempts.create` | repoint FK to `student_users` |
| `content_access_logs.user_id` | any role with `books.view`/`chapter_materials.view` (student, teacher, parent_guardian, and staff all hold it) | polymorphic `user_type`/`user_id`, no FK |
| `chapter_video_progress.user_id` | "any authenticated user" per `UpdateChapterVideoProgressRequest::authorize()` | polymorphic `user_type`/`user_id`, no FK |
| `timetable_entries.published_by` | only `timetable_entries.publish`, held only by staff roles | **unchanged** — stays FK'd to `users` |
| `student_section_transfers.transferred_by` | only staff roles have the update ability `transferSection` reuses | **unchanged** — stays FK'd to `users` |
| `books.pdf_uploaded_by_id` | only `trainer` has `books.create`/`update`, a staff role | **unchanged** — stays FK'd to `users` |
| `chapter_materials.uploaded_by_id` | only `trainer` has `chapter_materials.create`/`update`, a staff role | **unchanged** — stays FK'd to `users` |

The four `teacher_id`/`class_teacher_id` repoints and the one
`exam_attempts.user_id` repoint are simple FK-target changes (same
migration-guard pattern as the audit-columns migration: check the column
doesn't already point the new way before altering, so the migration is
re-runnable). The two polymorphic ones get the same `{column}_type`/
`{column}_id` treatment as `created_by`/`updated_by`, including the same
backfill rule.

## Model layer

`StudentUser`, `TeacherUser`, `GuardianUser` — each:

```php
class TeacherUser extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes, TenantScoped;

    protected $fillable = ['name', 'email', 'phone', 'password', 'role_id', 'school_id', 'district_id'];
    protected $hidden = ['password', 'remember_token'];

    public function role(): BelongsTo { return $this->belongsTo(Role::class); }
    public function school(): BelongsTo { return $this->belongsTo(School::class); }
    public function district(): BelongsTo { return $this->belongsTo(District::class); }
    public function profile(): HasOne { return $this->hasOne(TeacherProfile::class, 'user_id'); }
    public function hasPermission(string $permission): bool { return $this->role?->loadMissing('permissions')->hasPermission($permission) ?? false; }
}
```

(`StudentUser`/`GuardianUser` follow the same shape, with `profile()`
pointing at `StudentProfile`/`Guardian` respectively.)

`StudentProfile::user()`, `TeacherProfile::user()`, `Guardian::user()`
change their `belongsTo` target from `User::class` to `StudentUser::class`/
`TeacherUser::class`/`GuardianUser::class`.

## Auth layer

- `AuthController::teacherLogin()` queries `TeacherUser` instead of `User`
  (`TeacherProfile::where('employee_id', $login)->first()?->user` already
  resolves correctly once `user()` is repointed; the phone/email fallback
  queries `TeacherUser::where(...)`).
- `AuthController::studentLogin()` queries `StudentUser` the same way.
- `AuthController::guardianLogin()` (new) queries `GuardianUser` by
  phone/email, same shape as `managementLogin()`.
- `AuthController::login()`/`managementLogin()` are unchanged — `users`
  only.
- `issuePortalToken()`'s generic signature
  (`?User $user, string $allowedRolesConfigKey`) loosens its type-hint to
  `?Authenticatable` so it can issue a token for any of the four models.
- No `config/auth.php` changes. Sanctum resolves `$request->user()` via the
  token's polymorphic `tokenable` relation, not via a named guard/provider
  per model.

## Policy layer

Every Policy's method signatures change `User $user`/`User $actor` to
`Authenticatable $user`/`Authenticatable $actor` (import changes from
`App\Models\User` to `Illuminate\Contracts\Auth\Authenticatable`). Nearly
every method body is untouched — they already only call
`$user->hasPermission(...)` or a relation, which works identically on the
concrete type at runtime.

**Exception, found while writing the implementation plan:** four call
sites assume the acting user has a `guardian()` relation (today, every
`User` row does, even if it's null for non-guardians) and use it to find
"the guardian profile this actor's own account is linked to":
`Guardian::scopeVisibleTo()`/`isVisibleTo()` (`$actor->guardian`),
`ExamAttemptPolicy::view()` (`$user->guardian`), and
`StudentProfilePolicy::viewDashboard()` (`$user->guardian`). Once a
guardian is a `GuardianUser` instead of a `User`, that relation doesn't
exist on `User` anymore (dead code — no `users` row can ever be a guardian
again post-migration) and needs to exist on `GuardianUser` instead, named
`profile()` for consistency with `StudentUser`/`TeacherUser`. All four call
sites change to check `$actor instanceof GuardianUser ? $actor->profile :
null` in place of `$actor->guardian`. `User::guardian()`,
`User::studentProfile()`, and `User::teacherProfile()` are removed
entirely (dead code once no `users` row can be a student/teacher/guardian).
One more call site needs the same treatment for a different relation:
`ExamAttemptController::start()` reads `$user->studentProfile` to find the
exam-taker's own profile — becomes `$user->profile` once the actor is a
`StudentUser`.

## New account-creation endpoints

`TeacherUserController`, `StudentUserController`, `GuardianUserController` —
each a slimmed-down `UserController`: `index`/`show`/`store`/`update`
(no `assignRole`, since the role is fixed by which endpoint you call, not
caller-supplied). `Store*Request`/`Update*Request` validate `name`,
`email`/`phone`, `password`, `school_id`/`district_id` (same
scope-defaulting as `UserController::store()` — omitted school/district
place the new row in the caller's own scope). Their Policies
(`TeacherUserPolicy` etc.) mirror `UserPolicy` exactly, reusing
`users.view`/`users.create`/`users.update`/`users.delete` as the permission
strings (so existing role grants — e.g. `school_admin` already having
`users.create`/`users.update` — keep working without any
`RolePermissionSeeder` change).

Routes: `Route::apiResource('teacher-users', TeacherUserController::class)`
(and `student-users`, `guardian-users`), same middleware group as `users`.

`StoreTeacherProfileRequest`/`StoreStudentProfileRequest`/
`StoreGuardianRequest`'s `user_id` rule changes from
`Rule::exists('users', 'id')` to `Rule::exists('teacher_users', 'id')` /
`student_users` / `guardian_users` respectively (and the corresponding
`Rule::unique('teacher_profiles'/'student_profiles'/'guardians', 'user_id')`
is unchanged — it was never checking the `users` table).

## Testing

- New factories: `StudentUserFactory`, `TeacherUserFactory`,
  `GuardianUserFactory`, same shape as `UserFactory`.
- `tests/Concerns/CreatesAuthorizedActors.php` gets equivalent
  `teacherActorWithPermissions()`/`studentActorWithPermissions()`/
  `guardianActorWithPermissions()` (or a parameterized version), since the
  existing `actorWithPermissions()` only builds `User` rows.
- Every existing test that builds a teacher/student/guardian actor via
  `User::factory()->create(['role_id' => ...])` moves to the matching new
  factory.
- New test: the data migration moves a `role=teacher` `users` row into
  `teacher_users` with the same `id`, and `teacher_profiles.user_id`
  continues to resolve correctly through the repointed relation.
- New tests for the three new controllers (store/update/view, scope
  defaulting), mirroring `UserAuthorizationTest`'s shape.
- New test: a `TeacherUser` actor creating a `Question` (or `Exam`) records
  `created_by_type = TeacherUser::class` / `created_by_id = <that actor's
  id>`, not `User::class` — the one assertion that actually proves the
  polymorphic audit columns work for a non-staff actor, which is the whole
  reason this project touches that code at all.
- New test: a `TeacherUser` can be assigned as `class_routines.teacher_id`/
  `subject_teacher_assignments.teacher_id`/`timetable_entries.teacher_id`/
  `sections.class_teacher_id` and the relation resolves to a `TeacherUser`
  instance, not a lookup failure.
- New test: a `StudentUser` reporting chapter-video progress, and a
  `TeacherUser` (or any role) downloading a book PDF, each record the
  correct `user_type`/`user_id` on `chapter_video_progress`/
  `content_access_logs`.
- Full `php artisan test` run at the end, same bar as every prior
  sub-project this session.
