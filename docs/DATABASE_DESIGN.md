# Database Design

**Status:** Phase 1. No application tables exist yet — only Laravel's default framework tables (`users`, `password_reset_tokens`, `sessions`, `cache`, `jobs`, etc.) plus Sanctum's `personal_access_tokens` table, created by the framework's own migrations. This document records the connection configuration and the **planned** schema for Phase 2+, so later work builds toward one consistent design instead of improvising per-module.

## 1. Connection (local development)

| Setting | Value |
|---|---|
| Driver | MySQL 8.0.30 |
| Host | `127.0.0.1` |
| Port | `3306` |
| Database | `education_management` |
| Username | `root` |
| Password | *(empty — local Laragon default; never used in any non-local environment)* |

Configured in `backend/.env` (never committed — see root `.gitignore`). The database already existed before this initialization and was confirmed empty; no destructive commands (`DROP`, `TRUNCATE`) were run against it, and none should be in future work without an explicit, separately-confirmed reason.

## 2. Conventions (to apply from the first real migration onward)

- **Tenant scoping:** every tenant-owned table carries an indexed `school_id` (and/or `district_id` for district-level entities), enforced via an Eloquent **global scope** — never relying on the frontend to filter by school. A record with no resolvable tenant must 404, never silently fall back to a default tenant.
- **Soft deletes** (`deleted_at`) on academically/financially significant tables (students, results, payments, etc.) — nothing with an audit requirement is hard-deleted.
- **Junction tables** for every many-to-many relationship (e.g., `course_enrollments`, `student_guardian`, `role_permissions`), following standard LMS/SIS schema practice.
- **Explainability via versioning:** scoring/competency tables retain prior computed states (`score_versions`) rather than overwriting, so a later change to weights/rubrics never makes an earlier result inexplicable.
- **Transactional writes:** any operation touching both a leaf record and a derived aggregate (e.g., posting a mark + recalculating class rank) is wrapped in a DB transaction.
- **Missing data ≠ zero:** a sub-indicator with no evidence is stored as `NULL`/`not_available`, and aggregate calculations explicitly exclude nulls from weighted averages rather than defaulting to 0.
- **Actor audit columns:** every table backed by its own `Store*Request` carries nullable `created_by`/`updated_by` FKs to `users.id` (`ON DELETE SET NULL`), populated by the `Auditable` model trait — never set directly by controllers.

## 3. Planned Entity Groups (🔹 ORIGINAL entities + 🆕 additions, from `inspiration/AI_Teacher_Training_Proposal_v2.md` §11 — none created in Phase 1)

| Group | Entities |
|---|---|
| Identity & org | `users`, `roles`, `permissions`, `role_permissions`🆕, `districts`, `schools`, `buildings`, `classrooms`, `classes`, `academic_years`🆕 |
| People | `teacher_profiles`, `student_profiles`, `guardians`🆕, `student_guardian`🆕 |
| Training/LMS | `courses`, `lessons`, `chapters`, `learning_objectives`, `course_assignments`🆕, `learning_sessions`, `video_progress_events`🆕, `course_enrollments`🆕 |
| Assessment | `questions`, `question_tags`🆕 (Bloom's level, competency code), `question_item_stats`🆕, `assessments`, `assessment_attempts`🆕, `answers`, `rubrics`, `rubric_scores`🆕 |
| Competency/scoring | `competencies`, `performance_snapshots`, `score_breakdowns`🆕, `score_versions`🆕, `appeals`🆕, `recommendations` |
| School operations | `attendance_records`, `results`, `exam_timetables`, `exam_seat_assignments`🆕, `class_timetables`, `timetable_constraints`🆕, `homework`, `homework_submissions`🆕 |
| Finance | `fee_categories`, `fee_invoices`🆕, `payments`🆕, `payment_gateway_transactions`🆕, `receipts` |
| Documents | `certificates`, `notices`, `admission_register_entries`, `stipend_records` |
| AI & review | `ai_generation_jobs`🆕, `ai_review_queue`🆕 (`status`: pending/approved/rejected, `approved_by`, `approved_at`) |
| Platform | `audit_logs`, `notifications`🆕, `sms_log`🆕 |

This list is the target data model for Phase 2+, not a build order. The `DEVELOPMENT_ROADMAP.md` defines which groups get built in which phase.

## 4. What Exists Right Now

Run `php artisan migrate:status` inside `backend/` to see the current (default-only) migration list. As of this initialization: Laravel's own `0001_01_01_*` framework migrations plus Sanctum's `personal_access_tokens` migration. No custom migration files exist in `backend/database/migrations/` yet.
