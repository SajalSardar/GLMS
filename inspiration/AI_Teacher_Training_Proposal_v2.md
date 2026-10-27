# AI Teacher Training & School Management System
## Technical Specification & Development Proposal (v2 — Expanded)

**Prepared:** October 2026
**Technology constraints:** Laravel (PHP) backend only · MySQL database · React or Vue frontend · Responsive Web Application + PWA (no native mobile app) · Target market: Bangladesh (Bangla and English medium schools)
**Status:** Confidential project proposal — implementation-ready draft pending client/education-authority approval

> **How to read this document:** Every section preserves the original proposal's requirements, marked **🔹 ORIGINAL**, exactly as scoped in `inspiration/Training_Learning_Analytics_Proposal.docx`. New research-backed recommendations are marked **🆕 NEW RECOMMENDATION** and are additive — nothing marked ORIGINAL has been removed or weakened. Supporting evidence and citations for every 🆕 item are in the companion file `Research_Notes_and_Sources.md`.

---

## 1. Executive Summary

**🔹 ORIGINAL**
This proposal describes a unified web platform combining teacher training, student results and learning progress, AI-assisted assessment, and analytics from school to national level. Teachers log in, complete assigned training, watch videos, answer questions, receive feedback, and track their progress. Administrators and education officers use authorized dashboards and reports to monitor progress, competency gaps, and learning trends.

The scope also includes student management, attendance, results, exam timetables, smart class timetables, fee collection, receipts, certificates, notices, homework, admission and stipend registers, and class/subject management.

The workflow is: **assign training → learn → assess → recommend support → reassess → monitor classroom and student progress.** Dashboards support five levels: student, teacher, school, district, and national.

**🆕 NEW RECOMMENDATION**
This expanded specification adds concrete technical decisions the original proposal intentionally left open (backend structure, authentication method, AI vendor, payment/SMS vendors, hosting region), a formal risk register, a phase-by-phase cost estimate in BDT/USD, and several commonly-expected EdTech-ERP features not in the original scope (parent/guardian communication, local payment-gateway integration, library and basic HR/payroll modules) — each flagged as **optional/later-phase** so core scope and budget stay unchanged unless the client deliberately opts in.

---

## 2. Objectives and Success Measures

**🔹 ORIGINAL**

| Objective | Platform Contribution |
|---|---|
| Teacher professional development | Track training, progress, and competency goals |
| Verify learning | Support pre-tests, in-video questions, post-tests, and rubrics |
| Identify learning gaps | Provide subject- and skill-based results and progress trends |
| Data-informed decisions | Provide reports and analytics at school, district, and national levels |
| Transparency and fairness | Offer explainable scores, data protection, and human review for important decisions |
| Simplify school operations | Bring daily academic and administrative work into one platform |

**🆕 NEW RECOMMENDATION — measurable success criteria per objective**

| Objective | Suggested measurable target (to be confirmed with the authority) |
|---|---|
| Teacher professional development | ≥85% of assigned teachers complete mandatory courses within the defined window |
| Verify learning | 100% of formal assessments trace to an approved, trainer-reviewed question bank |
| Identify learning gaps | Competency dashboards refreshed within 15 minutes of new result entry |
| Data-informed decisions | District/national reports generated without manual spreadsheet consolidation |
| Transparency and fairness | 100% of AI-assisted scores show the underlying evidence and allow an appeal |
| Simplify school operations | Reduction in parallel paper registers in pilot schools (baseline measured pre-launch) |

---

## 3. User Types and Permissions

**🔹 ORIGINAL**
- **National/Platform Administrator** — manage national settings, districts, schools, users, courses, analytics, and reports.
- **District/Regional Officer** — monitor assigned districts and schools, coordinate support.
- **Head Teacher/School Administrator** — manage students, teachers, classes, attendance, results, fees, timetables, reports.
- **Teacher** — complete training and assessments; manage class attendance, results, homework.
- **Student** — access permitted learning materials, assessments, results, personal progress.
- **Trainer/Content Reviewer** — create courses, review AI-generated questions, maintain rubrics and assessment quality.
- **Office/Accounts Staff** — manage fees, receipts, admission registers, official documents within assigned permissions.

Permissions are scoped by role and organizational hierarchy; users only access records they are authorized to view or edit.

**🆕 NEW RECOMMENDATION — additional roles & the permission model**
- **Parent/Guardian (new role, optional feature)** — read-only access to their own child's attendance, results, fees, and notices; no write access anywhere. Common expectation in modern school-management products and directly enables the new Communication module (Section 5.15).
- **IT/System Administrator (new, technical role)** — separate from National Administrator: manages integrations, API keys (AI, SMS, payment), backups, and audit log review, without access to student academic data by default. Separating "who configures the system" from "who sees student records" is a security best practice, not just an org-chart preference.
- **Auditor (new, optional role)** — read-only, time-boxed access to audit logs and score-history for compliance review, without edit rights anywhere.
- **Permission model implementation detail:** every role is paired with an explicit **scope** (`school_id`, `class_id`, and/or `district_id`) and enforced via Laravel Policies/Gates evaluated server-side on every API call — never via hiding UI elements alone (this reinforces the original Section 11 principle). A District Officer, for example, inherits Head-Teacher-level *read* permissions only within their assigned schools, never write access and never outside assignment. See `Research_Notes_and_Sources.md` §7.

---

## 4. Teacher Training and Learning Analytics

### 4.1 Login and Organization Structure

**🔹 ORIGINAL**
- Secure login, password reset, optional multi-factor authentication.
- Organization hierarchy: National → District/Region → School → Class → Teacher/Student.
- Validated bulk import using CSV/XLSX and audit logs for important changes.
- View/edit permissions based on each user's role.

**🆕 NEW RECOMMENDATION**
- Authenticate via **Laravel Sanctum** using cookie-based SPA session auth (not Passport/OAuth2, which is unnecessary complexity here — see `Research_Notes_and_Sources.md` §6).
- MFA delivered via **local SMS OTP gateway** (Section 5.15) as the primary channel, since Bangladesh school staff are more likely to have SMS-capable phones than authenticator-app familiarity; email OTP as a secondary option.
- Bulk import validation should produce a **row-by-row error report** (not just accept/reject the whole file), since CSV imports from existing paper registers are expected to be messy on first use.

### 4.2 Courses and Training

**🔹 ORIGINAL**
- Courses, learning paths, subjects, learning objectives, deadlines, completion rules.
- Approved public YouTube videos, chapters, captions/transcripts, supporting materials.
- Mandatory or optional training assigned by role, subject, school, district, or cohort.
- Training completion certificates when defined conditions are met.

**🆕 NEW RECOMMENDATION**
- Tag every learning objective against the **NCTB curriculum/competency code** it maps to (see `Research_Notes_and_Sources.md` §14) so school-level training data stays interpretable by education officials rather than inventing a parallel taxonomy.
- Generate Bangla captions/transcripts for Bangla-medium training videos using a **self-hosted Bangla ASR model (BanglaSpeech2Text)** rather than assuming a generic English-tuned transcription API performs well on Bangla audio — see Section 10 and `Research_Notes_and_Sources.md` §10.

### 4.3 Video Progress

**🔹 ORIGINAL**
- Mobile-friendly video pages, chapter navigation, resume playback, notes/bookmarks, captions where available.
- Checkpoint questions at defined points, progress saved server-side.
- Video playback alone is not treated as proof of attention or learning.

**🆕 NEW RECOMMENDATION**
- Implementation detail: the YouTube IFrame embed must include `enablejsapi=1` and initialize tracking on load (not lazy-load), since lazy-loaded players are not tracked by the API by default.
- Log time-jump anomalies (e.g., jumping from 10% to 90% in under a second) as a background-queued event for trainer visibility, consistent with the original proposal's "log suspicious jumps, don't treat alone as proof of misconduct" principle (Section 6) — implemented, not just stated.

### 4.4 Assessments and Question Bank

**🔹 ORIGINAL**
- Multiple-choice, true/false, matching, short-answer, scenario-based questions, rubric-based tasks.
- Question bank, randomized questions, time limits, attempt limits, passing scores, feedback, retake rules.
- Pre-test/post-test comparison and trainer review for uncertain or low-confidence assessments.

**🆕 NEW RECOMMENDATION**
- Every question record carries an explicit **Bloom's-taxonomy level tag** (recall / understand / apply / analyze / evaluate / create) — AI-drafted question sets skew toward recall-level items without this check (see `Research_Notes_and_Sources.md` §8), and surfacing the tag lets a trainer catch an unbalanced quiz before publishing.
- Formal item-bank hygiene: track **difficulty and discrimination statistics** per question from real attempt data over time (a standard psychometric practice), flagging questions that are too easy/hard or that weak students answer correctly more often than strong students (a sign of a flawed question).

### 4.5 AI-Assisted Learning Assistant

**🔹 ORIGINAL**
- Draft questions from approved transcripts and learning objectives.
- Provide explanations, summaries of common misconceptions, refresher/practice recommendations.
- Assist with rubric-based review of written answers; send uncertain responses for human review.
- **Trainers must approve AI-generated questions before they are used in formal assessments.**

**🆕 NEW RECOMMENDATION — concrete AI vendor, workflow, and cost control**
- **Primary AI vendor: OpenAI.** Use **GPT-4o-mini** for question drafting, explanation generation, and rubric-assist scoring (the high-volume, moderate-complexity tasks); reserve full **GPT-4o** for lower-volume, higher-stakes generation (e.g., a full competency-comparison narrative). See Section 10 for the full AI integration design and `Research_Notes_and_Sources.md` §9.
- **Review-queue workflow (new, concrete):** AI output always lands in a `status = pending_review` state in the question bank; a Trainer must `approve`, `edit-then-approve`, or `reject` before an item becomes eligible for any formal assessment. This is the same rule the original proposal already states — the addition is the explicit database-level state machine that makes it unbypassable in code, not just policy.
- Run AI generation jobs through **Laravel Horizon-managed queues**, never synchronously in a web request, and use OpenAI's **Batch API** (50% cost discount) for overnight bulk drafting plus **Prompt Caching** (up to 90% discount on repeated input) since the same approved transcript is reused across many question-generation calls.
- Periodic **random audit sampling** of approved AI-assisted content (e.g., 5% monthly) to catch quality drift or bias the trainer-approval step might miss over time — a practice directly drawn from current LLM-assessment research (`Research_Notes_and_Sources.md` §8).

### 4.6 Teacher Competency and Student Progress

**🔹 ORIGINAL**
- Show course completion, assessment results, competency achievement, improvement over time, observations.
- Provide personal development plans and reassessment steps.
- Track student progress by subject, skill, term, class; flag learners who may need support.
- Student outcomes are influenced by many factors; teachers are not judged solely by raw student scores.

**🆕 NEW RECOMMENDATION**
- No change to the underlying principle; the implementation in Section 7 (Performance & Competency Assessment) adds a concrete multi-factor scoring model with explainability requirements that operationalizes "not judged solely by raw scores."

### 4.7 School, District, and National Dashboards

**🔹 ORIGINAL**
- Training coverage, course completion, pass rates, competency gaps, student progress.
- Filters by period, school, district, subject, class, course, user group.
- Charts, detailed lists, scheduled reports, CSV/XLSX/PDF exports (subject to approved requirements).

**🆕 NEW RECOMMENDATION**
- Dashboard aggregates are **Redis-cached with a visible "last updated" timestamp** (5–15 minute TTL) rather than computed live on every page view — necessary once district/national-level queries aggregate many schools; see Section 9 (Architecture) and `Research_Notes_and_Sources.md` §4.
- Every export (CSV/XLSX/PDF) is generated server-side through the **same permission-scoped API Resource classes** used for on-screen data, so an export can never leak a field the viewer isn't authorized to see on screen.

---

## 5. School Management Modules

**🔹 ORIGINAL** (Section header note, preserved): *All modules below are included in the proposed scope. Report layouts, print formats, and business rules are finalized during detailed requirements analysis.*

### 5.1 Dashboard — 🔹 ORIGINAL
At-a-glance counts for students, teachers, staff, buildings, classrooms. Today's attendance and fee collection. Monthly calendar; summary cards link to authorized detail lists/reports.

### 5.2 Student Management — 🔹 ORIGINAL
Student admission and profile creation/editing. Class promotion. CSV import/export with data validation.

### 5.3 Attendance Management — 🔹 ORIGINAL
Student daily/monthly/quarterly/annual attendance reports. Teacher daily/monthly/annual reports. Record leave, casual leave, medical leave.

### 5.4 Results Management — 🔹 ORIGINAL
Marks entry and Excel import/export. One-page and two-page learning progress reports. Class results, merit ranking, result summaries.

### 5.5 Exam Timetable and Admit Cards — 🔹 ORIGINAL
Create and print exam timetables, downloadable in Word-compatible format. Print two admit cards per A4 page. Seat-level seating layout: 16 cards/labels per page (subject to final design approval).

### 5.6 Smart Class Timetable — 🔹 ORIGINAL
Automatically generate a weekly timetable based on teachers, classes, and subjects. Detect and avoid conflicts (e.g., one teacher assigned to multiple classes at the same time).

**🆕 NEW RECOMMENDATION:** Implement as a **constraint-satisfaction (CSP) solver** run as a queued background job (generation can take real seconds-to-minutes for larger schools and must never block a web request). During requirements analysis, explicitly separate **hard constraints** (never violated — teacher double-booking, room capacity) from **soft constraints** (preferred but relaxable — minimizing teacher gaps/travel between buildings), since that distinction drives solver design. Benchmark research suggests conflict-free generation in under 5 minutes for schools up to ~60 teachers/~30 classes is realistic. See `Research_Notes_and_Sources.md` §20.

### 5.7 Live Timetable — 🔹 ORIGINAL
Show a live clock and animation indicating which class is currently in progress.

### 5.8 Fee Collection and Receipts — 🔹 ORIGINAL
Configure multiple fee categories, calculate totals automatically. Display monetary amounts in Bangla words. Print duplicate-copy receipts. Rules for outstanding balances, discounts, payment history configured separately.

**🆕 NEW RECOMMENDATION — named payment integration:** Integrate **bKash and Nagad** (Bangladesh's dominant mobile financial services) via the `laravel-bdpaygate` package or an **SSLCommerz** aggregator, so the school isn't locked to a single wallet. Every payment must be **confirmed server-side with the gateway** before an invoice is marked paid — never trust a client-reported "success" callback alone, the same server-side-validation principle the original proposal already applies to video-progress tracking. See `Research_Notes_and_Sources.md` §12.

### 5.9 Certificates — 🔹 ORIGINAL
Create/print commendation certificates, transfer certificates, letterhead documents. Support photos, logos, watermarks as required.

### 5.10 Notice Board — 🔹 ORIGINAL
Publish and store notices. Print A4 notices with school logo.

### 5.11 Homework — 🔹 ORIGINAL
Create and publish homework by class and subject. Print homework for multiple/all classes together.

### 5.12 Student Tracking — 🔹 ORIGINAL
Track skill achievement against learning indicators. Show A/B/C/D grades; grading criteria/thresholds require education-authority approval.

### 5.13 Admission and Stipend Registers — 🔹 ORIGINAL
Maintain a full admission register. Store stipend demand information. If the 85% attendance condition is applied, configure attendance source, calculation period, and exceptions per official policy.

### 5.14 Class and Subject Management — 🔹 ORIGINAL
Add classes, configure subjects. Copy existing settings; assign subjects by academic year/class.

### 5.15 Communication & Notifications — 🆕 NEW RECOMMENDATION (new module, not in original scope)
- **Parent/Guardian notifications** via SMS (through the abstracted SMS gateway, see Section 9) and in-app notices for: attendance absence, exam results published, fee due/received, important notices.
- **Web Push notifications** (PWA-native, no extra vendor needed) for teachers/staff: training deadlines, timetable changes.
- Built behind a single `NotificationChannel` abstraction in Laravel (database, SMS, push, email) so individual notification types can be routed per-channel without rework later.
- **Why added:** parent communication is one of the most consistently requested features in school-management products and is low-cost to add given the SMS gateway is already required for OTP (Section 4.1). Flagged as **Phase 3+ / optional** so it doesn't inflate the MVP.

### 5.16 Library Management (Optional, later phase) — 🆕 NEW RECOMMENDATION
Basic catalog, book issue/return tracking, overdue alerts to students via the notification layer. Scoped as an **optional add-on module**, not core — included here because it's a common gap identified when comparing this proposal's module list against mainstream school-ERP products, but it has no dependency on the AI/training workflow and can be built or skipped independently without affecting the core platform.

### 5.17 Staff HR/Payroll (Optional, later phase) — 🆕 NEW RECOMMENDATION
Basic staff attendance-linked payroll calculation and payslip generation, reusing the attendance data already captured in Section 5.3. Scoped as **optional/Phase 4+** — most pilot deployments will continue using existing payroll processes until the core academic modules are proven.

---

## 6. Video Viewing and Course Completion Reliability

**🔹 ORIGINAL**
Important limitation: public YouTube embeds cannot guarantee that seeking/skipping is completely prevented. Player monitoring can discourage casual skipping, but browser-side checks can be bypassed.

For the first release, use the YouTube IFrame Player API to monitor player state/time, save progress, and present checkpoint questions. Course completion should not rely only on the browser reporting 100% playback; server-side rules validate checkpoints and required assessments. Log suspicious time jumps for review, but do not treat them alone as conclusive evidence of misconduct. If stricter controls are needed, consider a secure managed-streaming service; even then, all screen recording or client-side manipulation cannot be prevented.

**🆕 NEW RECOMMENDATION**
Research confirms this limitation is accurate, not overly cautious (`Research_Notes_and_Sources.md` §17) — no change to the stated approach. If/when stricter controls become a real requirement (e.g., licensed paid content, not just public videos), the recommended managed-streaming option is **Bunny Stream** (token-authenticated, server-signed playback URLs, cost-effective) with **Mux** as the alternative where DRM-grade protection is a hard contractual requirement. This remains an explicitly optional Phase 2+/3 upgrade — not a default cost in this proposal's baseline budget. See `Research_Notes_and_Sources.md` §18.

---

## 7. Performance and Competency Assessment

**🔹 ORIGINAL**
A multi-dimensional profile is recommended instead of an opaque single ranking. The weights below are illustrative discussion values only and require education-expert approval before formal use.

| Indicator | Example Weight | Evidence |
|---|---|---|
| Training completion | 15% | Records of assigned courses and activities |
| Assessment results | 30% | Validated quizzes and post-tests |
| Competency achievement | 25% | Subject assessments and competency evidence |
| Classroom practice | 20% | Structured observation and rubrics |
| Student progress | 10% | Progress over time, when data is valid and available |

Illustrative configurable bands (not official standards): 90–100 = Advanced; 75–89 = Competent; 60–74 = Developing; below 60 = Additional Support Required.

Show each sub-score and the evidence behind it. Missing data must not silently be treated as zero. Show improvement over time as well as individual scores. Provide human review and a correction/appeal process for important decisions.

**🆕 NEW RECOMMENDATION — implementation of explainability**
- Every composite score persists a **`score_breakdown` record** (one row per sub-indicator per computation), not just the final number — so "show each sub-score and the evidence behind it" is a stored, queryable fact rather than a UI-only computation that could silently change if recalculated later.
- Missing-data handling is enforced at the schema level: a sub-indicator with no underlying evidence is stored as `NULL`/`not_available`, and the aggregate score calculation explicitly excludes nulls from the weighted average rather than defaulting them to 0 — directly implementing the original proposal's own stated rule.
- A `score_versions` table retains every historical computation of a given score (not just the latest), so if weights or rubrics change, prior results remain explainable exactly as they were produced at the time — this is the same principle the original proposal states in Section 10 ("version history… so changes to questions, rubrics, or scoring rules do not make it impossible to explain how earlier results were produced"), now expressed as a concrete table.
- A simple **appeal workflow** (new, concrete): a teacher/student can flag a score for review, which creates an `appeal` record visible to the relevant Trainer/Head Teacher, with a resolution status and audit trail — operationalizing "provide a correction/appeal process."

---

## 8. Mobile-Friendly Web Application (PWA)

**🔹 ORIGINAL**
One responsive React + TypeScript web application for desktop, tablet, mobile. Installable as a PWA in supported browsers; no separate React Native or native app. Bangla and English interface, readable fonts, sufficient color contrast, keyboard support, screen-reader considerations. Mobile users can access training, videos, quizzes, results, attendance, and essential school tasks. PWA limitations: offline YouTube playback is not always available and depends on the video provider/browser; notification support can vary by device/browser.

**🆕 NEW RECOMMENDATION — concrete offline/caching design and accessibility target**
- **Caching strategy by asset type** (service worker): Cache-First for the app shell (HTML/CSS/JS), Network-First-with-cache-fallback for API data (dashboards, results), Stale-While-Revalidate for images/certificates.
- **IndexedDB, not `localStorage`**, for any offline dataset beyond trivial key/value state (e.g., a queued quiz attempt or attendance entry taken while offline) — `localStorage`'s ~5MB synchronous model doesn't fit this use case.
- Offline actions (e.g., marking attendance with no connectivity) are queued locally and flushed via the **Background Sync API** when connectivity returns, with a visible "Saved offline — will sync" indicator rather than a generic spinner, so low-connectivity rural users always know their data wasn't lost.
- Explicit **WCAG 2.2 AA** conformance target (upgrading the original's general accessibility language into a testable acceptance criterion) — see Section 14 (Testing & Acceptance Criteria) and `Research_Notes_and_Sources.md` §21/§16.
- Clarified limitation (consistent with the original): **video content itself cannot be cached offline** under YouTube's terms — only quiz/attendance/text data benefits from the offline-first design above.

---

## 9. Proposed Technical Architecture

**🔹 ORIGINAL**

| Layer | Technology | Responsibility |
|---|---|---|
| Web frontend | React + TypeScript + PWA | Responsive pages, forms, charts, video player, accessible UI |
| Backend API | Laravel (PHP) only | Authentication, permissions, courses, assessments, school modules, reports, business logic |
| Database | MySQL | Users, schools, students, attendance, results, fees, questions, audit records |
| Background processing | Laravel Queues; Redis optional | AI requests, email, reports, scheduled tasks |
| AI | AI service accessed securely by Laravel | Question drafts, feedback, rubric assistance, recommendations |
| Video | YouTube initially | Video playback, progress, captions/transcripts; managed streaming may be evaluated if needed |
| Files | Private object storage or secured server storage | Reports, certificates, imports, approved materials |
| Operations | Docker/CI/CD, monitoring, backups | Deployment, error monitoring, recovery |

Backend decision: **Laravel is the sole backend framework in this proposal.** NestJS and a second backend are not included.

**🆕 NEW RECOMMENDATION — expanded architecture decisions**

| Layer | Original | New/concrete decision | Why |
|---|---|---|---|
| Backend structure | Unspecified | **Modular monolith**: domain modules (Training, Assessment, SchoolOps, Finance, Reporting, Notifications) each own their models/controllers/logic; cross-module communication via Laravel events, not direct calls | Keeps a single Laravel codebase simple to deploy while preventing the "everything imports everything" tangle common in school-ERP codebases as modules grow — see §1 |
| Multi-tenancy | Unspecified | **Shared MySQL database, `school_id`-scoped rows + mandatory global scopes** on every tenant-owned model for Phase 1; documented upgrade path to schema/DB-per-tenant if a future client needs hard isolation | Matches the scale and budget of an initial pilot; avoids premature infrastructure cost — see §1 |
| Authentication | Unspecified | **Laravel Sanctum**, cookie-based SPA session auth | Lower XSS exposure than bearer tokens for a first-party SPA/PWA; Passport's OAuth2 server is unneeded complexity since no third-party developer access is in scope — see §6 |
| API design | Unspecified | Versioned **`/api/v1/...`** routes; every response passes through an explicit **Eloquent API Resource** class | Enforces role-safe field serialization in code, not just hidden UI — directly reinforces the original Section 11 principle — see §5 |
| Background jobs | "Laravel Queues; Redis optional" | **Redis + Laravel Horizon required (not optional)**, with dedicated queues (`ai`, `imports`, `reports`, `notifications`) and `balance: auto` worker scaling | AI calls and bulk imports must never block a web request; separate queues stop one slow AI call from starving a report job — see §2 |
| Caching | Not present | **Redis-cached dashboard aggregates** (5–15 min TTL, visible "last updated" timestamp) | District/national dashboards aggregate many schools; computing live on every page view doesn't scale — see §4 |
| Database read scaling | Not present | Documented **trigger-based path**: cache first → add a read replica for reporting once concurrent dashboard load genuinely requires it → shard only if writes exceed one primary | Avoids paying for infrastructure (replicas) before pilot-scale usage needs it — see §4 |
| Hosting | Not specified | **AWS ap-south-1 (Mumbai)** for the pilot phase; **sovereign/local Bangladesh hosting (e.g., Pico Public Cloud) flagged as a required evaluation item before national/government rollout**, since U.S. CLOUD Act jurisdiction over AWS may be a procurement blocker at that scale | Balances mature tooling now against a realistic future data-residency requirement — see §15 |
| Video | YouTube initially | Confirmed as the correct default; **Bunny Stream** named as the specific managed-streaming upgrade path if/when needed | See §17–18 |
| SMS | Not present | Abstracted `SmsGatewayInterface` behind a local BTRC-regulated aggregator (e.g., sms.bd, EasySendSMS) | Used for OTP/MFA and the new parent-notification feature (§5.15); avoids vendor lock-in — see §13 |
| Payments | Not present | `laravel-bdpaygate` or SSLCommerz, bKash + Nagad priority | See §12 |
| AI service | "AI service accessed securely by Laravel" (unnamed) | **OpenAI** (GPT-4o-mini primary, GPT-4o for low-volume/high-stakes); Bangla transcription via a **self-hosted Bangla ASR model**, not assumed-equivalent generic transcription | See §9–10 |

---

## 10. AI Integration — Design and Options

**🔹 ORIGINAL (carried forward unchanged as a requirement, now made concrete below):**
"AI service accessed securely by Laravel" handling question drafts, feedback, rubric assistance, and recommendations, with trainer approval required before formal use.

**🆕 NEW RECOMMENDATION — vendor, model selection, and integration pattern**

### 10.1 Vendor and model selection
Per the agreed direction, **OpenAI is the named primary AI vendor** for this specification.

| Task | Recommended model | Why |
|---|---|---|
| Draft quiz/question items from approved transcripts | GPT-4o-mini | High volume, moderate complexity — $0.15/1M input, $0.60/1M output tokens (Oct 2026 pricing) |
| Short feedback/explanation generation | GPT-4o-mini | Same cost profile; low latency needed for in-app feedback |
| Rubric-assisted scoring of written answers | GPT-4o-mini, escalate low-confidence cases to human review | Matches the original proposal's existing rule to send uncertain responses for human review |
| Full competency/comparison narrative reports (low volume) | GPT-4o | Quality matters more than per-call cost at this volume |
| English audio transcription/captions | `gpt-4o-mini-transcribe` (or Whisper) | ~$0.003–0.006/minute |
| **Bangla audio transcription/captions** | **BanglaSpeech2Text (self-hosted)** | Generic Whisper-class models are not Bangla-optimized; a dedicated Bangla ASR model gives materially better accuracy and keeps audio data in-country — see `Research_Notes_and_Sources.md` §10 |

Cost-control techniques to apply from day one: OpenAI's **Batch API** (50% discount) for non-real-time bulk drafting runs, and **Prompt Caching** (up to 90% discount on repeated input tokens) since the same approved transcript/learning-objective text is reused across many generation calls.

### 10.2 Integration pattern (Laravel)
1. A Trainer triggers "Generate questions from this chapter" (or a scheduled job does so in bulk).
2. Laravel dispatches a job onto the dedicated `ai` queue (Horizon-managed, never synchronous).
3. The job calls the OpenAI API with the approved transcript + learning objective as context; the response is parsed and stored with `status = pending_review`.
4. The Trainer reviews each item in a review queue UI: approve / edit-then-approve / reject. Only `approved` items become eligible for use in a live assessment.
5. A scheduled job periodically samples a percentage of approved AI-assisted items for **random audit review**, flagging drift for a senior trainer.

### 10.3 Responsible-AI guardrails (reinforcing original Section 11/4.5 principles, made concrete)
- AI output is always labeled as AI-assisted in the UI wherever a human sees it pre-approval.
- No AI-generated score or question is used in a formal, graded assessment without the stored `approved_by`/`approved_at` audit fields being populated.
- Low-confidence AI rubric-scoring results are routed to a human-review queue automatically (confidence threshold configurable by the Trainer/education authority).
- All AI API calls and their inputs/outputs are logged (with PII minimized in the log where feasible) to support the audit/explainability requirements in Sections 7 and 11.

### 10.4 Alternatives considered (for completeness, not the current recommendation)
- **Self-hosted open-source LLMs** (e.g., Llama/Mistral class models) were considered for maximum data residency, but add meaningful infrastructure/ops burden (GPU hosting, model updates) relative to the pilot's scale; revisit if data-residency requirements tighten (see Section 9 hosting discussion) or at a scale where self-hosting becomes cost-competitive with API usage.
- **Anthropic Claude** was considered as an alternative primary vendor; not selected here per the agreed direction toward OpenAI, but the integration pattern above is vendor-agnostic enough (a single `AiProviderInterface` in Laravel) that switching or multi-vendor fallback remains straightforward if pricing/quality shifts.

---

## 11. Proposed Data Model

**🔹 ORIGINAL**
Core entities include users, roles/permissions, districts, schools, buildings, classrooms, classes, teacher/student profiles, courses, lessons, chapters, learning sessions, progress, competencies, questions, assessments, answers, rubrics, performance snapshots, recommendations, attendance, results, exam timetables, class timetables, fees, receipts, certificates, notices, and audit logs.

Version history should be retained so that changes to questions, rubrics, or scoring rules do not make it impossible to explain how earlier results were produced.

**🆕 NEW RECOMMENDATION — expanded entity list and key design patterns**

### 11.1 Expanded entity groups

| Group | Entities (new ones marked 🆕) |
|---|---|
| Identity & org | `users`, `roles`, `permissions`, `role_permissions` 🆕, `districts`, `schools`, `buildings`, `classrooms`, `classes`, `academic_years` 🆕 |
| People | `teacher_profiles`, `student_profiles`, `guardians` 🆕, `student_guardian` 🆕 (junction) |
| Training/LMS | `courses`, `lessons`, `chapters`, `learning_objectives`, `course_assignments` 🆕, `learning_sessions`, `video_progress_events` 🆕, `course_enrollments` 🆕 (junction) |
| Assessment | `questions`, `question_tags` 🆕 (Bloom's level, competency code), `question_item_stats` 🆕 (difficulty/discrimination), `assessments`, `assessment_attempts` 🆕, `answers`, `rubrics`, `rubric_scores` 🆕 |
| Competency/scoring | `competencies`, `performance_snapshots`, `score_breakdowns` 🆕, `score_versions` 🆕, `appeals` 🆕, `recommendations` |
| School operations | `attendance_records`, `results`, `exam_timetables`, `exam_seat_assignments` 🆕, `class_timetables`, `timetable_constraints` 🆕, `homework`, `homework_submissions` 🆕 |
| Finance | `fee_categories`, `fee_invoices` 🆕, `payments` 🆕, `payment_gateway_transactions` 🆕, `receipts` |
| Documents | `certificates`, `notices`, `admission_register_entries`, `stipend_records` |
| AI & review | `ai_generation_jobs` 🆕, `ai_review_queue` 🆕 (status: pending/approved/rejected, `approved_by`, `approved_at`) |
| Platform | `audit_logs`, `notifications` 🆕, `sms_log` 🆕 |

### 11.2 Design patterns applied across the schema
- **Tenant scoping:** every tenant-owned table carries an indexed `school_id` (and/or `district_id`), enforced via Eloquent global scopes (see Section 9).
- **Soft deletes** (`deleted_at`) on all academically/financially significant records (students, results, payments) — nothing is hard-deleted where an audit trail matters.
- **Explainability via versioning:** `score_versions` and `question` edit history retain prior states rather than overwriting, directly implementing the original proposal's own Section 10 principle.
- **Junction tables** (`course_enrollments`, `student_guardian`, `role_permissions`) for every many-to-many relationship, matching standard LMS/SIS schema practice (`Research_Notes_and_Sources.md` §3).
- **Transactional writes**: any operation touching both a leaf record and a derived aggregate (e.g., posting a mark + recalculating class rank) is wrapped in a DB transaction so a partial failure can't desynchronize a report.

---

## 12. API Structure

**🆕 NEW SECTION (not present in the original proposal) — added because the brief explicitly requests a defined API structure**

### 12.1 Conventions
- Base path: **`/api/v1/...`**, with `app/Http/Controllers/Api/V1/` namespacing; a future `V2` controller would extend `V1` and override only changed methods.
- Every response is shaped by an explicit **Eloquent API Resource** (never a raw model dump), so role-based field visibility is enforced in code.
- Authentication via **Sanctum** session cookie (`/sanctum/csrf-cookie` → `/login` → session-authenticated requests).
- Standard envelope: `{ "data": ..., "meta": { "page", "per_page", "total" } }` for collections; RFC 9457-style problem-detail JSON for errors.
- Rate limiting via Laravel's built-in throttle middleware, tuned per endpoint class (e.g., stricter on `/auth/*` and `/ai/*`).

### 12.2 Representative endpoint groups (illustrative, finalized during detailed requirements analysis)

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

---

## 13. Security, Privacy, and Responsible AI

**🔹 ORIGINAL**
- Check role and school-level permissions on every API request; hiding buttons in the interface alone is not security.
- Use TLS/HTTPS, secure password hashing, secret management, rate limiting, input validation, audit logs, regular backups.
- Collect only necessary personal data; define retention/deletion policies and export controls.
- Apply additional protections to minors' data; follow applicable consent and legal requirements.
- Present AI output as assistance; use human review for important decisions.
- Clearly distinguish individual results from aggregated statistics; document correction/appeal procedures.

**🆕 NEW RECOMMENDATION**
- **Bangladesh Data Protection Act — live compliance tracking required.** Bangladesh's data protection law exists only in draft form as of this writing (various 2023/2026 drafts) and is not yet fully enacted — this must be re-checked before go-live, since it may be finalized during the project timeline. Draft principles consistently require: consent-based processing, purpose limitation, security-by-design, Data Protection Impact Assessments for high-risk processing, and registration of data controllers/processors. See `Research_Notes_and_Sources.md` §11.
- **Adopt FERPA/COPPA-equivalent practices now, voluntarily**, as a best-practice baseline ahead of local law being finalized: written data-processing agreements with every third-party vendor touching student data (OpenAI, the SMS gateway, the payment gateway), restricted collection/use of minors' data, and clear parental/guardian rights to view, correct, and request deletion of a child's data (directly enabled by the new Parent/Guardian role, Section 3).
- **Hosting/data-residency as an explicit open item**, not a buried assumption: AWS Mumbai is recommended for the pilot, but a sovereign/local Bangladesh hosting option must be formally evaluated before any national or government-wide rollout, given U.S. CLOUD Act jurisdiction over AWS. See `Research_Notes_and_Sources.md` §15.
- **WCAG 2.2 AA** adopted as an explicit, testable accessibility conformance target (see Section 14).
- Vendor API keys (OpenAI, SMS gateway, payment gateway) stored via Laravel's encrypted environment/secret management, never committed to source control, rotated on a defined schedule, and scoped to the IT/System Administrator role only (Section 3).

---

## 14. Scalability Considerations

**🆕 NEW SECTION (expands the original's implicit scalability assumptions into an explicit, trigger-based plan)**

| Stage | Trigger | Action |
|---|---|---|
| 1 (pilot) | Default | Single MySQL primary, Redis cache for dashboard aggregates, Horizon-managed queues on a modest worker count |
| 2 | Dashboard/report queries show latency or lock contention under concurrent district-level usage | Add a **MySQL read replica** dedicated to reporting/analytics queries, keeping transactional writes on the primary |
| 3 | AI/background job volume grows (bulk question generation, bulk imports at scale) | Scale Horizon worker count and queue count; apply OpenAI Batch API more aggressively for non-real-time work |
| 4 | National-scale concurrent users across many districts | Horizontal scaling of stateless Laravel app servers behind a load balancer (sessions already externalized via Sanctum + Redis); evaluate sharding only if write volume genuinely exceeds one primary — not before |
| Ongoing | — | CDN for static frontend assets and certificate/report PDFs; connection pooling; indexed `school_id`/`district_id`/`user_id` columns on every tenant-scoped table |

This explicitly avoids over-building infrastructure (e.g., paying for read replicas or sharding) before pilot-scale usage demonstrates the need — see `Research_Notes_and_Sources.md` §4.

---

## 15. Implementation Plan

**🔹 ORIGINAL**

| Phase | Indicative Duration | Work | Completion Criteria |
|---|---|---|---|
| 0. Requirements and design | 2–3 weeks | Workshops, user journeys, scoring, wireframes, data/privacy review | Approved requirements, prototype, prioritized backlog |
| 1. Core web/MVP | 6–8 weeks | Login, roles, school structure, courses, video, progress, quizzes, dashboard | End-to-end flow tested with pilot users |
| 2. AI and competencies | 4–6 weeks | Question drafts, feedback, competency map, pre/post tests | Reviewed AI output and auditable assessment |
| 3. School modules and analytics | 4–6 weeks | Students, attendance, results, fees, timetables, reports, exports | Validated indicators and permission-tested dashboards |
| 4. Pilot and launch | 3–4 weeks | User testing, security, training, backup/restore, release | Approval, support plan, recovery test |

**Estimated total: 19–27 weeks.** Planning estimate; actual timing depends on feature priorities, data availability, integrations, AI services, and approval cycles. The solution remains one responsive web application/PWA, not a separate native app.

**🆕 NEW RECOMMENDATION — revised phase breakdown incorporating new scope**

| Phase | Revised duration | Added work vs. original | Completion criteria (added) |
|---|---|---|---|
| 0. Requirements & design | 2–3 weeks | *(unchanged)* + confirm Bangladesh DPA status, confirm hosting region decision, confirm NCTB competency-code mapping | + signed-off data-residency and compliance decisions |
| 1. Core web/MVP | 7–9 weeks | + Sanctum auth, modular-monolith scaffolding, versioned API structure | + automated cross-tenant leakage tests passing |
| 2. AI and competencies | 5–7 weeks | + OpenAI integration (GPT-4o-mini), Bangla ASR integration, AI review-queue state machine, score_versions/appeals tables | + reviewed AI output, auditable assessment, appeal workflow demoed |
| 3. School modules and analytics | 5–7 weeks | + bKash/Nagad payment integration, SMS gateway integration, CSP-based timetable solver, Redis dashboard caching | + validated indicators, permission-tested dashboards, successful live payment-gateway transaction in sandbox |
| 3b. Communication module (optional) | +2–3 weeks if included | Parent/guardian notifications, Parent role, Web Push | + parent test-notification delivered end-to-end |
| 4. Pilot and launch | 4–5 weeks | + WCAG 2.2 AA audit, load test against Stage-2 scalability triggers, security review of AI/payment/SMS integrations | + approval, support plan, recovery test, accessibility audit passed |

**Revised estimated total: 23–31 weeks core scope (≈26–36 weeks if the optional Communication module is included).** Still one responsive web application/PWA — no native app in any phase.

---

## 16. Deliverables

**🔹 ORIGINAL**
- Requirements document and role/permission matrix.
- UX flows, design system, responsive pages, dashboard prototype.
- React web application/PWA and Laravel backend source code.
- Database schema, migrations, reference data, and API documentation.
- Course/video module, assessments, progress tracking, AI integration.
- Student, teacher, school, district, and national dashboards.
- Agreed school modules: attendance, results, timetables, fees, receipts, notices, homework, certificates, admission/stipend, class/subject management.
- Automated tests, deployment pipeline, administrator guide, handover, initial training documentation.

**🆕 NEW RECOMMENDATION — additions**
- Documented **API Resource/serialization map** showing exactly which fields each role can see per endpoint (operationalizes the permission matrix at the API level).
- **AI integration runbook**: prompts/templates used, review-queue operating procedure, audit-sampling schedule.
- **Data Protection Impact Assessment (DPIA) document**, aligned to the draft Bangladesh DPA and the voluntary FERPA/COPPA-equivalent baseline.
- **WCAG 2.2 AA accessibility audit report**.
- **Vendor integration guides** for the SMS gateway and payment gateway (credentials rotation procedure, sandbox-to-production cutover steps).
- If Section 5.15–5.17 optional modules are included: their own scoped deliverables, clearly itemized separately in the contract so they can be dropped without affecting core pricing.

---

## 17. Testing and Acceptance Criteria

**🔹 ORIGINAL**
- Trainers can create and publish courses with learning objectives, videos, chapters, and approved questions.
- Teachers can resume courses, answer checkpoint questions, complete assessments, and view results/feedback.
- Course completion follows server-side rules and required assessment conditions.
- AI-generated questions are not used in formal assessments without approval.
- Each administrator can view only data within their authorized scope.
- Dashboard figures match source records, and exports enforce the same permissions.
- Login, courses, assessments, results, and PWA installation are tested on mobile browsers.
- Backup restoration, responsive layouts, accessibility, security, and print templates are tested.

**🆕 NEW RECOMMENDATION — made testable/specific**
- **Cross-tenant leakage tests**: automated feature tests that log in as School A and attempt to access School B's data on every endpoint must return 403/404, run in CI on every deploy (see `Research_Notes_and_Sources.md` §1).
- **Accessibility**: WCAG 2.2 AA conformance verified with both automated scanning and a manual screen-reader pass, not automated scanning alone.
- **Payment integration**: at least one successful end-to-end sandbox transaction each for bKash and Nagad, including the server-side confirmation step, before go-live.
- **AI review-queue**: demonstrate that an unapproved AI-drafted question is structurally unable to appear in a live formal assessment (a negative test, not just a policy statement).
- **Timetable solver**: generate a conflict-free timetable for a representative pilot school within an agreed time budget (e.g., under 5 minutes), with zero hard-constraint violations.
- **Load/scale rehearsal**: simulate the Stage-2 scalability trigger (Section 14) in a staging environment before any district-wide pilot expansion, not only at national scale.
- **Offline/PWA**: verify a queued offline attendance/quiz-attempt entry syncs correctly once connectivity returns, using the Background Sync API design in Section 8.

---

## 18. Risks and Mitigations

**🆕 NEW SECTION (not present in the original proposal) — added because the brief explicitly requests a risk register**

| Risk | Likelihood/Impact | Mitigation |
|---|---|---|
| Bangladesh Data Protection Act is enacted mid-project with requirements not yet anticipated | Medium / High | Treat as a live-tracked item (Section 13); build consent, retention, and DPIA practices now rather than after the fact; budget a contingency review pass before launch |
| YouTube embed limitations allow determined users to bypass checkpoint/progress tracking | High / Low-Medium (already acknowledged, not a surprise) | Server-side completion rules (already in original scope); anomaly logging; Bunny Stream/Mux upgrade path available if stakes rise |
| AI-generated content contains errors, bias, or culturally inappropriate framing | Medium / High if unreviewed | Mandatory trainer-approval gate (original scope) + Bloom's-level tagging + periodic random audit sampling (Section 10) |
| Payment gateway outage or API changes (bKash/Nagad/SSLCommerz) | Medium / Medium | Gateway-abstraction layer (`laravel-bdpaygate`/aggregator) so a single vendor outage doesn't halt all fee collection; manual-entry fallback for Office/Accounts Staff |
| Low-bandwidth/rural connectivity undermines usability | Medium / Medium | PWA offline-first design (Section 8); SMS as fallback notification channel, which doesn't require continuous connectivity |
| Scope creep from the 17 total modules (14 original + 3 new optional) | Medium / Medium | Optional modules (5.15–5.17) explicitly priced/scheduled separately; MVP (Phase 1) scoped tightly per the original Section 16 "Recommended First Release" |
| NCTB curriculum revisions change competency codes mid-project | Low-Medium / Medium | Competency-code mapping (Section 4.2) isolated behind a lookup table, not hard-coded, so curriculum updates don't require a schema migration |
| Vendor lock-in to a single AI provider | Low / Medium | `AiProviderInterface` abstraction (Section 10.4) keeps the OpenAI integration swappable |
| Hosting jurisdiction (U.S. CLOUD Act over AWS) blocks a future government procurement requirement | Medium / High at national scale | Local/sovereign hosting evaluated explicitly before national rollout (Section 9/13), not assumed away |
| Key person/vendor dependency during a 23–31 week build | Medium / Medium | Modular monolith with clear module boundaries (Section 9) allows different developers to own different modules without full-codebase context |

---

## 19. Cost Considerations

**🔹 ORIGINAL**
"Commercial pricing, payment milestones, service levels, hosting, and post-launch support will be defined in the contract." Detailed cost and delivery commitments prepared after the feature list, user count, report samples, privacy rules, and implementation priorities are approved.

**🆕 NEW RECOMMENDATION — indicative budget model**

> These are **planning-level estimates only**, built from published Bangladesh market-rate benchmarks (`Research_Notes_and_Sources.md` §22) and Oct 2026 AI/cloud vendor pricing. They are not a quotation. Reference rate used: **1 USD ≈ 123 BDT.**

### 19.1 Development labor (core scope, Phases 0–4, excluding optional modules)
Using a benchmark 5-person dedicated team (2 developers, 1 QA, 1 DevOps, 1 tech lead) at a cited **$18,000–30,000/month** for a South Asia-based team:

| Scenario | Duration | Monthly cost (USD) | Total (USD) | Total (BDT, approx.) |
|---|---|---|---|---|
| Lean team / lower end | 26 weeks (~6 months) | $18,000 | ~$108,000 | ~13,280,000 BDT |
| Fuller team / upper end | 31 weeks (~7.2 months) | $30,000 | ~$216,000 | ~26,570,000 BDT |

*If the Section 5.15–5.17 optional modules are included, add roughly 1–2 months of the same team-cost range on top (~$18,000–60,000 / ~2.2M–7.4M BDT), scheduled as Phase 3b/4+ and contracted separately so they can be deferred without affecting core pricing.*

### 19.2 AI usage (OpenAI), ongoing monthly estimate once live
Rough illustrative volume: 500 teachers, ~4 training modules/teacher/term, each generating ~20 AI-assisted questions + explanations, plus Bangla transcription for ~50 hours of video content/term.

| Item | Estimated monthly volume | Unit cost (Oct 2026) | Estimated monthly cost |
|---|---|---|---|
| Question/feedback generation (GPT-4o-mini) | ~2M input + 1M output tokens | $0.15/1M in, $0.60/1M out | ~$0.90/month before caching discounts |
| Occasional GPT-4o narrative reports | ~200K input + 100K output tokens | $2.50/1M in, $10/1M out | ~$1.50/month |
| English transcription (gpt-4o-mini-transcribe) | ~20 hours/month | ~$0.003/min | ~$3.60/month |
| **Bangla transcription** | Self-hosted BanglaSpeech2Text | Infrastructure cost only (no per-minute fee) | Covered under hosting, not AI API cost |

**Note:** at this scale, AI *API* cost is genuinely small (a few dollars/month) — the real AI-related cost driver is **engineering time to build the integration and review-queue workflow** (already reflected in Section 19.1), not ongoing token spend. This should be communicated clearly to the client so AI cost isn't over-budgeted.

### 19.3 Infrastructure (monthly, pilot scale)

| Item | Estimated monthly cost (USD) | Notes |
|---|---|---|
| AWS ap-south-1 app server(s) + managed MySQL + Redis | $150–$400 | Scales with concurrent users; pilot-scale estimate |
| Object storage (reports/certificates/imports) | $10–$30 | Low at pilot scale |
| CDN | $10–$20 | Static assets + PDF delivery |
| Bangla ASR self-hosting (BanglaSpeech2Text) | $50–$150 | If GPU/CPU instance needed; can share infra with other background jobs at pilot scale |
| SMS gateway (OTP + notifications) | Pay-per-SMS, typically ৳0.20–0.50/SMS locally | Budget scales directly with parent-notification adoption (Section 5.15) |
| Payment gateway fees (bKash/Nagad/SSLCommerz) | Transaction-based, typically 1.5–2.5% per transaction | Standard local merchant rates; confirm current rates with each provider directly |

### 19.4 Items explicitly excluded from the above (confirm separately, per original Section 15)
- Third-party licensing for any premium design system/UI kit, if used.
- Formal third-party security penetration test (recommended before national rollout; typically a fixed-fee external engagement).
- Post-launch support/maintenance retainer (recommend a separate SLA-based monthly contract, not embedded in build cost).
- Sovereign/local hosting migration cost, if national rollout requires moving off AWS (Section 13).

---

## 20. Assumptions and Items to Confirm

**🔹 ORIGINAL**
- Confirm whether the initial rollout covers one school, multiple schools, a district, or national operations.
- Confirm existing data sources, identity verification requirements, and import formats.
- Ensure permission to embed the required YouTube videos.
- AI, video hosting, cloud, SMS/email, and third-party licensing costs may be separate.
- The authority must approve scoring, A/B/C/D grades, the 85% attendance condition, data retention, and access policies.
- Commercial pricing, payment milestones, service levels, hosting, and post-launch support will be defined in the contract.
- Approve sample designs for A4 admit cards, 16 seat-level cards/labels per page, Word/PDF output, and other print documents.

**🆕 NEW RECOMMENDATION — additional items to confirm**
- Confirm current status of the **Bangladesh Data Protection Act** (draft vs. enacted) at contract signing and re-confirm before go-live.
- Confirm whether **AWS Mumbai hosting is acceptable** for the pilot, or whether a local/sovereign hosting requirement already applies (e.g., for a government-run pilot).
- Confirm whether **Parent/Guardian communication (Section 5.15)** and the other optional modules (5.16–5.17) are in scope for this engagement or deferred.
- Confirm **which mobile wallets** (bKash, Nagad, others) the authority/schools actually require, since gateway-aggregator choice depends on this.
- Confirm whether **NCTB competency-code mapping** is available/required at launch or can be phased in.
- Confirm the acceptable **AI review-queue staffing model** — i.e., who are the named Trainers/Content Reviewers responsible for approving AI-drafted content, and their expected turnaround time.

---

## 21. Recommended First Release

**🔹 ORIGINAL**
Begin with a reliable teacher-training workflow: secure login → assigned course → video lesson → required checkpoint questions → AI-assisted, trainer-approved quiz → score and explanation → personal next steps → administrative reporting.

For school management, student records, attendance, results, and timetables may be prioritized first. Fees, certificates, notices, homework, and stipend registers can follow in later phases. Final prioritization should be based on budget and user needs.

**🆕 NEW RECOMMENDATION**
No change to this prioritization — research reinforces it is sound (a tight, testable MVP reduces risk on the highest-uncertainty parts of the system: AI review workflow and core training flow). Added specificity: the MVP should also include the **Sanctum auth + versioned API foundation** (Section 9) from day one, since retrofitting authentication/API structure after other modules are built is significantly more expensive than building on top of it from the start. The optional modules (Communication, Library, HR/Payroll — Section 5.15–5.17) are explicitly *not* part of this first release under any budget scenario.

---

## 22. Conclusion

**🔹 ORIGINAL**
This platform can evolve a conventional learning analytics dashboard into an integrated system for teacher training, assessment, student learning progress, and everyday school management. Its core value is to connect approved training, reliable assessment, transparent competency evidence, timely support, and school/student progress in one secure, mobile-friendly web application.

Detailed cost and delivery commitments can be prepared after the feature list, user count, report samples, privacy rules, and implementation priorities have been approved.

**🆕 NEW RECOMMENDATION**
This expanded specification keeps that core value proposition unchanged while closing the gaps a technical implementation team would otherwise have to resolve mid-build: a named AI vendor and integration pattern, a concrete multi-tenant architecture, a defined API structure, local payment/SMS vendor integration appropriate to the Bangladesh market, an explicit risk register, and a planning-level BDT/USD cost model. All new recommendations are additive and separately identified (🆕) so the client/education authority can approve the original scope as-is and adopt new recommendations selectively, module by module, without renegotiating the whole proposal. Full supporting research and citations are in `Research_Notes_and_Sources.md`.
