# Development Roadmap

**Status:** Phase 1 (this initialization) is complete or in final verification — see `PROJECT_ANALYSIS.md` for what Phase 1 does and does not include. The phases below are planning guidance, combining the original proposal's phasing (🔹 ORIGINAL, from the docx §15) with the revised, research-backed phasing (🆕, from `inspiration/AI_Teacher_Training_Proposal_v2.md` §15). Durations are planning estimates, not commitments.

## Phase 0 — Project Initialization (this work)

- Backend (Laravel) and frontend (React + TS + Vite PWA) scaffolds created, independently runnable.
- MySQL `education_management` connected, default framework migrations run.
- `/api/v1` structure established with one real endpoint (`/health`).
- `docs/` written.
- **Not included:** any domain module, any AI integration, any auth flow beyond installing Sanctum.

## Phase 1 — Core Web / MVP (🔹 ORIGINAL: 6–8 weeks → 🆕 revised: 7–9 weeks)

Dependencies: Phase 0 complete.

- Sanctum login/logout/MFA endpoints; roles & permissions tables, Policies/Gates scoped by `school_id`/`district_id`.
- School/district/class organizational structure (`districts`, `schools`, `classes`, `classrooms`, `academic_years`).
- Course/lesson/chapter models, YouTube IFrame video progress tracking with checkpoint questions, server-side completion rules (no reliance on 100%-playback self-report).
- Quiz/question-bank schema (without AI drafting yet) and basic student/teacher dashboards.
- **Completion criteria (🆕 made testable):** end-to-end flow tested with pilot users; automated cross-tenant leakage tests passing in CI (log in as School A, confirm every endpoint 403/404s against School B's data).

## Phase 2 — AI and Competencies (🔹 ORIGINAL: 4–6 weeks → 🆕 revised: 5–7 weeks)

Dependencies: Phase 1 auth/roles/course schema.

- `AiProviderInterface` + OpenAI (GPT-4o-mini primary) implementation, called only from Horizon-managed queued jobs.
- AI question-drafting from approved transcripts; mandatory `pending_review` → Trainer `approve`/`edit-then-approve`/`reject` state machine — enforced at the database level, not just by policy.
- Bloom's-taxonomy tagging per question; `question_item_stats` (difficulty/discrimination) tracked over time.
- Bangla audio transcription via self-hosted BanglaSpeech2Text; English via `gpt-4o-mini-transcribe`/Whisper.
- `score_breakdowns`, `score_versions`, `appeals` tables — explainability and correction workflow.
- **Completion criteria:** reviewed AI output and auditable assessment; negative test proving an unapproved AI-drafted question cannot appear in a live formal assessment.

## Phase 3 — School Modules and Analytics (🔹 ORIGINAL: 4–6 weeks → 🆕 revised: 5–7 weeks)

Dependencies: Phase 1 org structure.

- Student management, attendance, results/marks entry, exam timetables & admit cards, Smart Class Timetable (CSP solver run as a queued job), fee collection with bKash/Nagad (server-side payment confirmation mandatory), certificates, notice board, homework, admission/stipend registers, class/subject management.
- Redis-cached dashboard aggregates (5–15 min TTL, visible "last updated" timestamp) at school/district/national level.
- **Completion criteria:** validated indicators, permission-tested dashboards, successful sandbox transaction for both bKash and Nagad, conflict-free timetable generation under an agreed time budget (e.g., 5 minutes) with zero hard-constraint violations.

## Phase 3b — Communication Module (optional, +2–3 weeks)

Dependencies: Phase 3 SMS gateway (already required for OTP).

- Parent/Guardian role (read-only), SMS + in-app + Web Push notifications for attendance/results/fees/notices, behind a single `NotificationChannel` abstraction.
- **Not in the core MVP under any budget scenario** — confirm with the client whether this is in scope (see `OPEN_QUESTIONS.md`).

## Phase 4 — Pilot and Launch (🔹 ORIGINAL: 3–4 weeks → 🆕 revised: 4–5 weeks)

Dependencies: all prior phases for whatever module set is in scope.

- User testing, WCAG 2.2 AA accessibility audit (automated scan + manual screen-reader pass), security review of AI/payment/SMS integrations, load/scale rehearsal against the Phase-2 scalability trigger (read replica), backup/restore test, release.
- **Completion criteria:** approval, support plan, recovery test, accessibility audit passed.

## Later / Optional (not scheduled)

- Library Management (5.16), Staff HR/Payroll (5.17) — explicitly scoped as optional add-ons with no dependency on the AI/training workflow; can be built or skipped independently.
- Sovereign/local Bangladesh hosting migration — required evaluation before any national/government rollout, not before.
- Database read replica, horizontal app-server scaling, sharding — trigger-based, only when pilot-scale usage actually demonstrates the need (see `inspiration/AI_Teacher_Training_Proposal_v2.md` §14).

## Immediate Next Phase (recommended)

Per the Definition of Done for this initialization: **authentication, users, roles, permissions, and school management** — i.e., the start of Phase 1 above. Recommended first concrete steps: Sanctum login/logout endpoints and feature tests, the `roles`/`permissions`/`role_permissions` schema, and the `districts`/`schools`/`classes` organizational tables with `school_id` global scopes applied from the very first tenant-owned model (retrofitting tenant scoping later is far more expensive than building on top of it from day one).
