# Project Analysis

**Status:** Phase 1 (foundation initialization). This document summarizes the source requirements and distinguishes what the client originally asked for from what has since been recommended or decided during technical setup.

## 1. Source of Truth

- **Original requirements document:** `inspiration/Training_Learning_Analytics_Proposal.docx` — a confidential project proposal, dated October 2026, for an **AI-Powered Teacher Training, Learning Analytics & School Management Platform** targeting Bangla/English-medium schools in Bangladesh.
- **Expanded technical specification:** `inspiration/AI_Teacher_Training_Proposal_v2.md` — an annotated version of the same proposal where every section is marked either 🔹 **ORIGINAL** (verbatim scope from the docx) or 🆕 **NEW RECOMMENDATION** (additive technical/research-backed suggestions). This file's 🔹 ORIGINAL sections were checked against a raw text extraction of the source `.docx` during this initialization and confirmed to match.
- **Supporting research:** `inspiration/Research_Notes_and_Sources.md` — the evidence trail (findings + external sources) behind every 🆕 recommendation.
- **Reference images:** five WhatsApp screenshots in `inspiration/` (supplementary visual context provided alongside the proposal; not yet individually catalogued — see `OPEN_QUESTIONS.md`).

This document and the rest of `docs/` describe **Phase 1 only**: the empty-but-working technical foundation. No business/domain modules are implemented yet.

## 2. Original Objectives (🔹 ORIGINAL)

| Objective | Platform Contribution |
|---|---|
| Teacher professional development | Track training, progress, and competency goals |
| Verify learning | Pre-tests, in-video questions, post-tests, rubrics |
| Identify learning gaps | Subject- and skill-based results and progress trends |
| Data-informed decisions | Reports/analytics at school, district, and national levels |
| Transparency and fairness | Explainable scores, data protection, human review |
| Simplify school operations | One platform for daily academic and administrative work |

The workflow the platform must eventually support: **assign training → learn → assess → recommend support → reassess → monitor classroom and student progress**, with dashboards at five levels (student, teacher, school, district, national).

## 3. Full Original Scope (🔹 ORIGINAL)

- **Teacher training & learning analytics**: login/org hierarchy, courses & training (YouTube-based video), video progress/checkpoints, assessments & question bank, AI-assisted drafting with mandatory trainer approval, teacher competency & student progress tracking, dashboards at 5 levels.
- **School management modules (14 total)**: dashboard, student management, attendance, results, exam timetable & admit cards, smart (auto-generated) class timetable, live timetable, fee collection & receipts, certificates, notice board, homework, student tracking (A/B/C/D grades), admission & stipend registers, class/subject management.
- **Platform**: one responsive React + TypeScript web app, installable as a PWA, Bangla + English UI, no native mobile app, Laravel (PHP)-only backend, MySQL database.

## 4. New Recommendations Layered On Top (🆕, from `AI_Teacher_Training_Proposal_v2.md`)

These are additive and do not replace or weaken any original requirement; they close gaps the original proposal intentionally left open (vendor choices, concrete architecture, risk register, cost model):

- **Auth**: Laravel Sanctum (cookie-based SPA session auth), not Passport.
- **Multi-tenancy**: shared MySQL database, `school_id`-scoped rows with mandatory Eloquent global scopes; documented upgrade path to per-tenant DB isolation later.
- **AI vendor**: OpenAI (GPT-4o-mini primary, GPT-4o for low-volume/high-stakes), behind a vendor-agnostic `AiProviderInterface`; self-hosted BanglaSpeech2Text for Bangla audio transcription.
- **Payments**: bKash + Nagad via `laravel-bdpaygate` or SSLCommerz, server-side payment confirmation mandatory.
- **SMS**: abstracted `SmsGatewayInterface` over a local BTRC-regulated aggregator, for OTP/MFA and (optional, later-phase) parent notifications.
- **New optional roles/modules** (explicitly *not* core MVP): Parent/Guardian portal, IT/System Administrator role, Auditor role, Library Management, Staff HR/Payroll.
- **Explainability**: `score_breakdowns`, `score_versions`, and `appeals` tables operationalizing "show evidence, don't silently zero missing data, allow appeal."
- **Accessibility**: explicit WCAG 2.2 AA conformance target.
- **Hosting**: AWS `ap-south-1` (Mumbai) for the pilot; sovereign/local Bangladesh hosting flagged as a required evaluation before any national/government rollout (U.S. CLOUD Act exposure on AWS).
- **Risk register, phase-by-phase BDT/USD cost model**: see `inspiration/AI_Teacher_Training_Proposal_v2.md` §18–19 for full detail; not reproduced here since it is a planning/commercial document, not a technical one.

## 5. Decisions Made During This Initialization (new, not in either source document)

| Decision | Value | Rationale |
|---|---|---|
| Backend framework/version | Laravel (latest release compatible with the installed PHP 8.5.12) | Matches the mandated "Laravel (PHP) only" constraint; exact version recorded in `ARCHITECTURE.md` once confirmed |
| Database connection | `mysql://root:@127.0.0.1:3306/education_management` | Matches local Laragon MySQL install; confirmed reachable and already created, empty |
| API base path | `/api/v1` | Required by the brief |
| Frontend framework | React + TypeScript + Vite (not Next.js) | No requirement in the source documents implies SSR, static generation, or a Next.js-specific feature; Vite is the simpler, explicitly-preferred default per the brief |
| Auth library installed now (not wired yet) | `laravel/sanctum` | Cheap to install at foundation time; full login/MFA flows deferred to Phase 2 per `DEVELOPMENT_ROADMAP.md` |

## 6. What Phase 1 Deliberately Does Not Include

Per the original proposal's own phasing (§15/§21) and the explicit Phase 1 instructions for this initialization: no student/teacher/school CRUD, no course/video/assessment logic, no AI integration, no payments/SMS, no multi-tenancy enforcement code, no full authentication flows. Phase 1 delivers the scaffolding these will be built on top of.
