# API Specification

**Status:** Phase 1. Only one real endpoint exists (`GET /api/v1/health`). Everything else below documents the **conventions** every future endpoint must follow and the **planned** endpoint groups (🆕 from `inspiration/AI_Teacher_Training_Proposal_v2.md` §12), not yet-built functionality.

## 1. Conventions (binding on all future endpoints)

- **Base path:** `/api/v1/...`, namespaced as `App\Http\Controllers\Api\V1\*` in the backend. A future `V2` would extend `V1` controllers and override only changed methods — never a silent breaking change to `V1`.
- **Serialization:** every response is shaped by an explicit Eloquent **API Resource** class (`php artisan make:resource`). Raw model dumps are never returned — this is how role-based field visibility is enforced in code, not just hidden in the UI (reinforces the original proposal's own Section 11 principle).
- **Envelope:**
  - Single resource / collection: `{ "data": ... }`, with `{ "data": [...], "meta": { "page", "per_page", "total" } }` for paginated collections.
  - Errors: RFC 9457-style problem-detail JSON.
- **Auth:** Laravel Sanctum bearer tokens, not cookie-based SPA sessions — `POST /api/v1/auth/login` (generic), or one of the three portal-specific endpoints (`POST /api/v1/auth/{teacher,student,management}/login`), each returning `{ "data": {...user...}, "token": "<plain-text-token>" }`. Every subsequent request carries `Authorization: Bearer <token>`. Chosen over cookie sessions because the three portals are three separate-subdomain SPAs with no shared cookie domain to configure.
- **Rate limiting:** Laravel's built-in throttle middleware, tuned per endpoint class (stricter on `/auth/*` and any future `/ai/*` routes).
- **Authorization:** every endpoint checks role + school/district scope server-side via Policies/Gates. No endpoint may rely on the frontend to filter what a user is allowed to see.

## 2. Endpoints That Exist Today

| Method | Path | Returns | Auth |
|---|---|---|---|
| `GET` | `/api/v1/health` | `{ "data": { "status": "ok", "timestamp": "<ISO8601>" } }` | None (public health check) |
| `POST` | `/api/v1/auth/teacher/login` | `{ "data": { ...UserResource... }, "token": "<plain-text-token>" }` | None (public; resolves by `teacher_profiles.employee_id`, phone, or email; `teacher` role only) |
| `POST` | `/api/v1/auth/student/login` | `{ "data": { ...UserResource... }, "token": "<plain-text-token>" }` | None (public; resolves by `student_profiles.student_id` only; `student` role only) |
| `POST` | `/api/v1/auth/management/login` | `{ "data": { ...UserResource... }, "token": "<plain-text-token>" }` | None (public; resolves by phone or email; `national_admin`/`district_officer`/`school_admin` roles only) |

## 3. Planned Endpoint Groups (🆕 illustrative — not built, finalized during detailed requirements analysis per module)

```
/api/v1/auth/login, /logout, /mfa/verify
/api/v1/users, /roles, /permissions
/api/v1/districts, /schools, /classes, /classrooms
/api/v1/courses, /courses/{id}/lessons, /courses/{id}/enroll
/api/v1/lessons/{id}/progress            (video progress events)
/api/v1/assessments, /assessments/{id}/attempts
/api/v1/questions, /questions/{id}/approve   (Trainer-only)
/api/v1/ai/generate-questions, /ai/review-queue   (Trainer-only)
/api/v1/students, /students/{id}/attendance, /students/{id}/results
/api/v1/attendance (bulk entry)
/api/v1/results (marks entry, Excel import)
/api/v1/exam-timetables, /exam-timetables/{id}/admit-cards
/api/v1/class-timetables/generate            (queued CSP job)
/api/v1/fees/invoices, /fees/payments, /fees/payments/{id}/confirm
/api/v1/certificates, /notices, /homework
/api/v1/dashboards/school/{id}, /district/{id}, /national
/api/v1/reports/export (CSV/XLSX/PDF, permission-scoped)
/api/v1/notifications, /sms/webhooks, /payments/webhooks
```

Each group above is built out module-by-module per `DEVELOPMENT_ROADMAP.md`, not all at once.

## 4. Frontend Consumption Pattern

The frontend's `frontend/src/lib/api.ts` reads `VITE_API_BASE_URL` from the environment and never hardcodes a backend host, so the same build can point at a different backend URL per environment without a code change. In Phase 1, it calls only `GET /health` from the dashboard placeholder page, to prove end-to-end connectivity.
