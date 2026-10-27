# Research Notes & Sources
### Supporting research for the AI Teacher Training & School Management System Proposal (v2)
*Compiled: October 2026*

This file documents the external research used to refine and expand the original proposal (`inspiration/Training_Learning_Analytics_Proposal.docx`). Each topic lists the key findings, how they shaped a recommendation in the main proposal (`AI_Teacher_Training_Proposal_v2.md`), and the sources consulted. Treat this as the evidence trail — the proposal itself states conclusions, this file shows the reasoning and citations behind them.

> **Note on currency:** Where costs are quoted, the reference exchange rate used throughout the proposal is **1 USD ≈ 123 BDT** (late October 2026 mid-market rate). Reconfirm this rate before finalizing any contract, since BDT has historically moved materially within a budget cycle.

---

## 1. Backend Architecture — Laravel at Scale

**Findings:**
- A **modular monolith** is the recommended middle ground between a tangled single-app monolith and premature microservices: each domain (training, assessment, attendance, fees, etc.) gets its own module with models/controllers/logic, modules communicate via Laravel events or defined service contracts rather than direct cross-module calls, and boundaries are enforced so one module can later be extracted if needed.
- For multi-tenancy (multiple schools/districts on one platform), three patterns exist: shared database with a `tenant_id`/`school_id` column, separate schemas per tenant, or fully separate databases per tenant. The common guidance is to **start with a single shared database** (indexed tenant column + global scopes) unless a tenant contractually requires stronger isolation, while architecting so a move to separate databases is possible later.
- `stancl/tenancy` is cited as the production-standard Laravel package for teams that do need database-per-tenant isolation.
- Security best practice: every tenant-owned Eloquent model should use a **global scope**; "no tenant" must resolve to a 404, never a default tenant; and automated tests should explicitly try to access another tenant's data from every endpoint to catch leakage.
- Performance: cache tenant lookups in Redis (TTL 15–60 min), index `tenant_id`/`school_id` columns, use connection pooling, and eager-load relations to avoid N+1 queries.

**Applied to this proposal:** Recommend a **modular monolith in Laravel** organized by domain (Training, Assessment, SchoolOps, Finance, Reporting), with a **shared-database, `school_id`-scoped** multi-tenancy model for phase 1 (single DB, global scopes, indexed foreign keys), with a documented upgrade path to schema-per-district if a future client requires hard isolation (e.g., a paying private school network that wants its data physically separated from government-pilot data).

**Sources:**
- [Exploring Modular Monolithic Architecture — Medium](https://medium.com/@harryespant/exploring-modular-monolithic-architecture-a-laravel-developers-guide-with-an-e-commerce-example-0548668ec222)
- [Laravel Multi-Tenancy 2026: Complete Multi-Tenant SaaS Guide — LaraCopilot](https://laracopilot.com/blog/laravel-multi-tenancy-saas-guide/)
- [How to Implement Multi-tenancy in Laravel — OneUptime](https://oneuptime.com/blog/post/2026-02-02-laravel-multi-tenancy/view)
- [Multi-Tenant SaaS in Laravel 2026: Tenancy v4 & Feature Flags — SoftTechOver](https://softtechover.com/post/building-multi-tenant-saas-with-laravel-tenancy-v4-pennant-complete-2026-architecture-guide)
- [Building Multi-Tenant SaaS with Row-Level Security in Laravel — DEV Community](https://dev.to/addwebsolutionpvtltd/building-multi-tenant-saas-with-row-level-security-in-laravel-3kd3)

---

## 2. Background Jobs — Laravel Horizon & Redis

**Findings:**
- Separate queues by priority/function (e.g., `ai-jobs`, `reports`, `notifications`, `imports`) rather than one `default` queue, so a slow job can't block unrelated work.
- Use `balance: auto` in Horizon so worker processes shift toward whichever queue is under load; size `minProcesses`/`maxProcesses` to actual server capacity.
- Run Horizon under Supervisor with `autostart`/`autorestart`; use `php artisan horizon:terminate` during deploys and `stopwaitsecs=3600` so in-flight jobs finish gracefully.
- Set `maxTime`/`maxJobs` on workers as cheap insurance against memory leaks in third-party packages (e.g., AI SDK calls).
- Set Redis `maxmemory-policy` to `allkeys-lru`/`volatile-lru` since Horizon stores job metrics in Redis and will otherwise exhaust memory.

**Applied to this proposal:** AI calls (question drafting, Bangla transcription, rubric-assisted scoring), bulk CSV/XLSX imports, scheduled report generation, SMS/email notifications, and timetable generation are all recommended as **queued background jobs** (dedicated `ai`, `imports`, `reports`, `notifications` queues) behind Laravel Horizon + Redis, never run synchronously inside a request.

**Sources:**
- [Laravel Horizon: Monitor and Scale Redis Queues in 2026 — LaraCopilot](https://laracopilot.com/blog/laravel-horizon-redis-queues/)
- [Running Laravel Queues in Production: Redis, Supervisor, Retries — DEV Community](https://dev.to/muhammadalimughal/running-laravel-queues-in-production-redis-supervisor-retries-and-failed-jobs-278m)
- [Scaling Laravel Queues: Horizon, Redis & Multi-Server Workers — RichDynamix](https://richdynamix.com/articles/scaling-laravel-queues-production-guide)

---

## 3. Database Schema Design — LMS / SIS

**Findings:**
- Core LMS pattern: many-to-many between users and courses via an `enrollments` junction table; one-to-many from courses to assignments/lessons.
- Standard SIS tables: students, courses, instructors, enrollments, scores/results, feedback/attendance.
- Best practices repeatedly cited: normalize to avoid redundancy (e.g., addresses/organizations in their own tables), index frequently-filtered columns (`user_id`, `course_id`, `school_id`), use DB transactions when writing grades/results so partial writes can't desynchronize a report, and encrypt/access-control sensitive fields.

**Applied to this proposal:** Confirms the original proposal's entity list (Section 10) is directionally correct; the expansion below adds explicit junction tables (`course_enrollments`, `question_tags`, `role_permissions`), a `score_versions`/audit pattern for explainability, and transactional writes for any operation that touches both a result and a derived aggregate (e.g., posting a mark + recalculating a class rank).

**Sources:**
- [How to Design a Database for a Learning Management System — GeeksforGeeks](https://www.geeksforgeeks.org/sql/how-to-design-a-database-for-learning-management-system-lms/)
- [Student Information System Database Schema & Design — DatabaseSample](https://databasesample.com/database/student-information-system-database)
- [Weekly DB Project #3: Building a Bulletproof SIS Database — Medium](https://medium.com/@bhargavkoya56/weekly-db-project-3-building-a-bulletproof-student-information-system-database-c910a139c17a)

---

## 4. Scaling MySQL Read Load (Dashboards/Reports)

**Findings:**
- Pragmatic scaling order: vertical scaling first → add a Redis cache for hot reads → add read replicas for broad read-heavy load (dashboards/reporting) → shard only once writes genuinely exceed a single primary.
- Analytics/reporting dashboards are a textbook case for **read replicas**, since they run heavy aggregate queries that would otherwise compete with transactional writes (attendance check-ins, fee payments) on the primary.
- Caching is most effective for data that is read far more than written, expensive to compute, and tolerant of a few minutes of staleness — a close match for school/district/national dashboard aggregates.

**Applied to this proposal:** Phase 1 runs on a single MySQL primary with Redis-cached dashboard aggregates (5–15 min TTL, explicit "last updated" timestamp shown to the user, never silently stale data presented as live). A **read replica for the reporting/analytics module** is scoped as a Phase 3+/scale-trigger item (e.g., when district or national rollout materially increases concurrent dashboard users), not a day-one requirement — avoiding infrastructure cost before it's needed.

**Sources:**
- [How to Scale MySQL with Caching — OneUptime](https://oneuptime.com/blog/post/2026-03-31-mysql-scale-with-caching/view)
- [Database Read Replicas Explained — PandaStack](https://pandastack.io/blog/database-read-replicas-explained)
- [Database Cache or Read-Only Replicas — Medium](https://medium.com/architecting-the-future/database-cache-or-read-only-replicas-choosing-the-right-approach-for-your-project-0b92cec7b985)

---

## 5. API Design — Laravel REST Conventions

**Findings:**
- URL-path versioning (`/api/v1/...`) remains the 2026 industry standard for Laravel: simplest to implement, test, debug, and cache; allows parallel maintenance of multiple versions.
- Recommended folder structure: `app/Http/Controllers/Api/V1/`, with V2 controllers extending V1 and overriding only changed methods.
- Use Eloquent **API Resources** (`php artisan make:resource`) to control exactly what's serialized, rather than returning raw models — critical here since many models (students, results) carry fields that must never leak across role boundaries.
- Deprecation discipline: publish migration notes before breaking changes, use `Sunset` headers when retiring a version.

**Applied to this proposal:** All endpoints are versioned under `/api/v1/`; every response passes through an explicit API Resource class so role-based field-hiding is enforced in code (not just hidden in the UI) — directly reinforcing the original proposal's Section 11 point that "hiding buttons in the interface alone is not security."

**Sources:**
- [Laravel API Versioning Strategy: Production Guide 2026 — Kokil](https://kokil.com.np/blog/laravel-api-versioning-strategy)
- [Laravel API Best Practices 2026 — Production-Ready Guide — Kokil](https://kokil.com.np/blog/laravel-api-best-practices)
- [REST API Development in Laravel: Complete Guide + Security — Umar Waqas](https://www.umarwaqas.com/index.php/blog/2026/02/rest-api-development-in-laravel-a-complete-guide)

---

## 6. API Authentication — Sanctum vs Passport

**Findings:**
- For a first-party SPA/PWA served from the same top-level domain as the Laravel API, **Sanctum's cookie-based SPA authentication** (HttpOnly, encrypted session cookie via `/sanctum/csrf-cookie`) is the recommended approach — it meaningfully reduces XSS exposure compared to bearer tokens.
- Passport (full OAuth2 server) is only needed when **external third-party developers** need delegated access to the API — not relevant to this platform's current scope.
- Never store API tokens in `localStorage` (XSS can exfiltrate them); set explicit token/session expirations.

**Applied to this proposal:** Recommend **Laravel Sanctum** (not Passport) for the React/Vue PWA and any future official mobile client, using cookie-based session auth for same-domain deployments and scoped personal-access tokens only for isolated server-to-server integrations (e.g., an EMIS data-exchange job).

**Sources:**
- [Laravel Sanctum vs Passport: A Simple Decision Tree — LaraCopilot](https://laracopilot.com/blog/laravel-sanctum-vs-passport-auth-2026/)
- [Laravel Sanctum vs Passport for Mobile Apps — DEV Community](https://dev.to/puneetpalarneja/laravel-sanctum-vs-passport-for-mobile-apps-2026-100c)

---

## 7. Hierarchical Role-Based Access Control (RBAC)

**Findings:**
- Hierarchical RBAC lets higher-level roles inherit lower-level permissions; standard variants are flat, hierarchical, constrained, and symmetric.
- In multi-tenant systems, a role must always be evaluated **in tenant context** — a role grants nothing outside its assigned school/district, and roles/permissions should be assignable per-tenant rather than globally fixed.
- A common advanced pattern layers **ABAC on top of RBAC**: use the role as one attribute, then add contextual attributes (e.g., "own class only," "own district only") for finer scoping than role name alone can express.

**Applied to this proposal:** Confirms and extends the original role list (Section 3) with an explicit **hierarchical + scoped permission model**: a role (Head Teacher, Teacher, etc.) is always paired with a scope (`school_id`, `class_id`, `district_id`), permission checks run as Laravel Policies/Gates evaluated against that scope on every request, and a District Officer role, for example, inherits read-level permissions of a Head Teacher *within assigned schools only* — never writes, never outside assignment.

**Sources:**
- [Multi-tenant Role-based Access Control (RBAC) — Aserto](https://www.aserto.com/use-cases/multi-tenant-saas-rbac)
- [Access Control SaaS Guide for B2B & Multi-Tenant Platforms — LoginRadius](https://www.loginradius.com/blog/engineering/rbac-saas-multi-tenant-b2b-platforms)
- [Advanced Multi-Tenant SaaS Authorization With Cerbos — Cerbos](https://www.cerbos.dev/blog/multi-tenant-saas-authorization-role-policies-and-scoped-resource-policies)

---

## 8. AI-Generated Assessment Content — Quality & Human Review

**Findings:**
- Recent (2025–2026) studies find LLM-generated multiple-choice questions can be **indistinguishable in quality from human-written ones when carefully validated** — but validation is the operative word; un-reviewed output is not reliable for formal/high-stakes assessment.
- Best-practice pipeline: generate with the LLM → automated quality screen (clarity, curriculum alignment, distractor plausibility) → **mandatory human expert review before formal use** → periodic random audits post-deployment to catch drift or emerging bias.
- A documented weakness: LLMs are good at recall-level questions but under-deliver on higher-order (Bloom's taxonomy "analyze/evaluate/create") items without careful prompting and review — reinforcing that trainers, not the model, own final question quality.

**Applied to this proposal:** Directly confirms and strengthens the original proposal's existing rule ("Trainers must approve AI-generated questions before they are used in formal assessments," Section 4.5). The expanded spec adds a concrete **review-queue workflow** (draft → pending-review status → trainer approve/edit/reject → only approved items enter the live question bank) and recommends trainers be shown the target Bloom's-taxonomy level per question so recall-only sets can be flagged before publishing.

**Sources:**
- [Evaluating the instrumental quality of LLM-generated assessment items — ResearchGate](https://www.researchgate.net/publication/407553758_Evaluating_the_instrumental_quality_of_LLM-generated_assessment_items)
- [AI versus human-generated MCQs for medical education — NCBI/PMC](https://www.ncbi.nlm.nih.gov/pmc/articles/PMC11806894/)
- [From Memorization to Creation: Cognitive Depth of LLM-Generated Questions — arXiv](https://arxiv.org/html/2606.18257v1)
- [Can LLMs generate school-level questions? — ScienceDirect](https://www.sciencedirect.com/science/article/pii/S2666920X25000104)

---

## 9. OpenAI API — Model Choice & Pricing (for budgeting)

**Findings (as of Oct 2026, confirm before contracting — AI vendor pricing changes frequently):**
- **GPT-4o-mini:** $0.15 / 1M input tokens, $0.60 / 1M output tokens, 128K context — the cost-effective default for high-volume, moderate-complexity tasks (question drafting from an approved transcript, short feedback generation, rubric-assist suggestions).
- **GPT-4o:** $2.50 / 1M input, $10.00 / 1M output — reserved for lower-volume, higher-stakes generation where quality matters more than per-call cost (e.g., drafting a full competency-rubric comparison report).
- **Whisper (audio transcription):** $0.006/minute flat. **gpt-4o-mini-transcribe** is roughly half that (~$0.003/minute) with improved accuracy over base Whisper.
- **Batch API:** 50% discount on standard pricing for non-real-time jobs (a strong fit for overnight bulk question-drafting runs).
- **Prompt Caching:** up to 90% discount on repeated input tokens — valuable here because the same approved video transcript/learning objective gets reused across many question-generation calls.

**Applied to this proposal:** Recommend **GPT-4o-mini** as the default model for question drafting, explanation generation, and rubric-assist scoring (per user's instruction, OpenAI is the named primary vendor), with the **Batch API** for bulk/offline jobs and **Prompt Caching** enabled on repeated transcript context to materially cut cost. **gpt-4o-mini-transcribe** (or Whisper) is recommended for English transcription/captioning; see Section 10 below for Bangla-specific transcription, where a specialized open-source model outperforms generic Whisper.

**Sources:**
- [OpenAI API Pricing: A Complete Breakdown for 2026 — G2](https://www.g2.com/articles/openai-api-pricing)
- [GPT-4o Mini Model — OpenAI Developers](https://developers.openai.com/api/docs/models/gpt-4o-mini)
- [OpenAI Transcribe & Whisper API Pricing (Oct 2026) — CostGoat](https://costgoat.com/pricing/openai-transcription)
- [OpenAI API Pricing Guide 2026 — TokenCalc](https://tokencalc.dev/blog/openai-api-pricing-guide-2026/)

---

## 10. Bangla Language AI Tooling (NLP / OCR / Speech)

**Findings:**
- **BanglaSpeech2Text** — an open-source, offline speech-to-text package fine-tuned from Whisper specifically for Bangla; models range from a 100–200MB "tiny" variant (WER ~74, i.e. weak) up to a 3–4GB "large" variant (WER ~11, usable quality). Running fully offline/self-hosted avoids per-minute API cost and keeps audio data in-country.
- **BanglaConformer** — a ~121M-parameter Conformer-CTC ASR model trained on the OOD-Speech dataset, another self-hostable option.
- **bbOCR (Bengali.AI-BRACU-OCR)** — an open-source, scalable Bengali document OCR system for digitizing scanned Bangla text (relevant to digitizing paper admission/stipend registers or handwritten notes, a later-phase idea).
- **BanglaBERT / BanglishBERT** — pretrained Bangla (and bilingual Bangla+English) language models usable for tasks like short-answer similarity scoring or search, if a future phase wants Bangla-aware semantic search rather than relying on a general-purpose multilingual model.

**Applied to this proposal:** Because the platform explicitly targets **Bangla-medium schools**, generic Whisper/GPT-4o-mini-transcribe English-tuned transcription is not assumed to be sufficient quality for Bangla classroom audio. The AI integration section recommends **BanglaSpeech2Text (self-hosted) as the primary option for Bangla audio-to-text** (captions/transcripts of Bangla training videos), with OpenAI's transcription APIs as the fallback for English-medium content — avoiding the common mistake of assuming one vendor's general multilingual model performs equally well in Bangla.

**Sources:**
- [BanglaSpeech2Text — GitHub](https://github.com/shhossain/BanglaSpeech2Text)
- [BanglaConformer — Hugging Face](https://huggingface.co/bengaliAI/BanglaConformer)
- [bangla-ocr topic — GitHub](https://github.com/topics/bangla-ocr)
- [Bengali.AI](https://www.bengali.ai/)

---

## 11. Bangladesh Data Protection Law & Children's Data

**Findings:**
- Bangladesh's **Data Protection Act** has existed in draft form since 2023 (variously referenced as the Draft/Revised Data Protection Act 2023 and a further-revised 2026 draft) and is **not yet a settled, fully enacted framework** at the time of writing — this is a material legal-risk item, not a footnote.
- Core principles consistent across drafts: lawful-purpose + consent-based processing (freely given, specific, revocable), data-minimization/purpose-limitation, mandatory security measures ("protection by design and by default"), Data Protection Impact Assessments for high-risk processing, record-keeping for inspection, retention limits, and **mandatory registration of data controllers/processors** handling citizens' data.
- Because no finalized Bangladesh-specific statute yet governs children's education data in detail, international frameworks (**FERPA** — U.S. education-records law, and **COPPA** — U.S. under-13 online data law) are widely used by EdTech vendors globally as a **de facto best-practice baseline** even outside the U.S., covering: data processing agreements with any vendor touching student records, restricting collection/use of minors' data, parental/guardian rights to inspect, correct, and request deletion of a child's data, and requiring consent before third-party disclosure.

**Applied to this proposal:** The Security & Privacy section is expanded to explicitly (a) track the **draft Bangladesh DPA** as a live compliance target that must be re-checked before go-live, since it may be enacted during the project timeline, and (b) adopt **FERPA/COPPA-equivalent practices as a voluntary best-practice baseline now** — parental/guardian visibility into a minor's data, data processing agreements with the AI/SMS/payment vendors, and a clear retention/deletion policy — rather than waiting for local law to force the issue later.

**Sources:**
- [An Overview of Bangladesh's Personal Data Protection Act — Securiti](https://securiti.ai/bangladesh-personal-data-protection-act-overview/)
- [Draft Personal Data Protection Act 2023 — Bangladesh — Regulations.ai](https://regulations.ai/regulations/bangladesh-2023-11-data-protection-act)
- [Bangladesh Data Protection Act Guide — GlobalPrivacyLaws](https://globalprivacylaws.com/laws/dpa-bangladesh/)
- [FERPA vs COPPA: Student Data Privacy for K-12 — ArmorStack](https://armorstack.ai/ferpa-coppa-student-data/)
- [A Guide to Understanding Compliance and Student Data Privacy in EdTech — Deledao](https://www.deledao.com/post/student-data-privacy-compliance-guide)

---

## 12. Local Payment Gateways (Fee Collection)

**Findings:**
- Multiple mature Laravel packages exist for Bangladeshi mobile financial services: individual packages for **bKash** (several maintained options, including tokenized checkout support), and a unified package — **laravel-bdpaygate** — that wraps bKash, Nagad, SSLCommerz, Upay, ShurjoPay, aamarPay, and others behind one consistent API, confirming every transaction with the gateway before marking an order/invoice paid server-side (never trusting a client-side "success" callback alone).

**Applied to this proposal:** The Fee Collection module (original Section 5.8) is extended with a concrete integration recommendation: use **SSLCommerz or the `laravel-bdpaygate` package** as an aggregator layer so the school isn't locked into a single mobile wallet, with **bKash and Nagad** as the two priority wallets given their dominant market share in Bangladesh, and server-side payment confirmation as a hard security requirement (matching the existing proposal's "server-side validation, not client-reported state" principle already used for video-progress tracking).

**Sources:**
- [laravel-bdpaygate — GitHub](https://github.com/byteminus/laravel-bdpaygate)
- [BDPayGate: Laravel Payment Gateway for bKash, Nagad, SSLCommerz](https://bdpaygate.github.io/)
- [programmerhasan/bkash — Packagist](https://packagist.org/packages/programmerhasan/bkash)

---

## 13. SMS Gateways (OTP & Notifications, Bangladesh)

**Findings:**
- Several BTRC-regulated local SMS aggregators offer REST APIs explicitly supporting Laravel/PHP, with OTP delivery typically under 5 seconds and both "masking" (branded sender ID) and "non-masking" SMS types — relevant choices: sms.bd, DOER, Alpha Net, EasySendSMS (covers Grameenphone/Robi/Banglalink/Teletalk networks), MiM SMS.

**Applied to this proposal:** Recommend an **SMS-gateway abstraction layer** (a single internal `SmsGatewayInterface` in Laravel) so the platform isn't hard-coded to one vendor — important in Bangladesh where gateway reliability and pricing shift vendor-to-vendor — used for OTP login/MFA, fee-payment confirmations, and attendance/exam-result alerts to parents (a new recommended feature, see proposal Section on Communication).

**Sources:**
- [OTP SMS & SMS API Bangladesh — sms.bd](https://sms.bd/)
- [EasySendSMS Gateway Bangladesh](https://www.easysendsms.com/gateway/Bangladesh)
- [Bulk SMS API Integration — Alpha SMS Gateway](https://www.alpha.net.bd/SMS/api/)

---

## 14. Education Context — Bangladesh EMIS / NCTB

**Findings:**
- Bangladesh's Ministry of Primary and Mass Education (MoPME) and Ministry of Education (MoE) each run **separate EMIS systems**; the government has an active initiative to rationalize/integrate these under a proposed Education Statistics Agency and unified Education Data Policy — meaning a national-level data exchange target exists but is **itself still being consolidated**, so no single stable external API can be assumed yet.
- NCTB (National Curriculum and Textbook Board) is the controlling authority for curriculum and textbook content at primary/secondary level, including periodic syllabus revisions (e.g., SSC exam-year syllabus notices) — any competency/skill taxonomy used in the platform should be mappable to NCTB's current curriculum structure, not an internally invented one, so school-level results stay interpretable by officials.

**Applied to this proposal:** Recommend the platform's competency/skill tagging schema be **designed to map onto NCTB subject/competency codes** from day one (even before any direct EMIS integration exists), and treat a future **EMIS data-exchange connector** as a distinct, separately-scoped Phase 4+ integration — not something to build speculatively now against an API that is itself still being unified.

**Sources:**
- [Education Management Information System — apps.emis.gov.bd](https://apps.emis.gov.bd/)
- [National Curriculum and Textbook Board — Wikipedia](https://en.wikipedia.org/wiki/National_Curriculum_and_Textbook_Board)
- [Partnership Compact — Global Partnership for Education](https://www.globalpartnership.org/node/document/download?file=document%2Ffile%2F2025-01-bangladesh-partnership-compact.pdf)

---

## 15. Cloud Hosting & Data Residency

**Findings:**
- AWS's Mumbai region (`ap-south-1`) is fully operational, multi-AZ, and MeitY-empaneled (approved for regulated government workloads in the region) — the closest major hyperscaler region to Bangladesh with mature managed-MySQL/Redis offerings.
- Caveat: AWS (a U.S. company) is subject to the **U.S. CLOUD Act**, meaning U.S. authorities can in principle compel data disclosure regardless of physical storage location — a real consideration for government/student-data workloads, not just a theoretical one.
- A Bangladesh-local alternative now exists: **Pico Public Cloud** (partnered with Mirantis) markets itself as the first sovereign public cloud in Bangladesh, offering in-country data residency, local-language support, and BDT billing.
- AWS Outposts can also be deployed on-premises in Bangladesh for workloads that must stay physically in-country while still integrating with a nearby AWS region for management.

**Applied to this proposal:** Recommend **AWS ap-south-1 (Mumbai)** as the default hosting region for the private pilot/early-adopter phase (mature tooling, lowest operational risk, acceptable latency to Bangladesh), while flagging a **sovereign/local hosting option (e.g., Pico Public Cloud) as a required evaluation item before any national/government-wide rollout**, since a government procurement or data-protection review may mandate in-country hosting outside U.S. jurisdiction. This is called out explicitly as an "item to confirm," not a default decision baked into pricing.

**Sources:**
- [AWS vs Indian cloud providers: data residency and compliance guide](https://vizowrite.com/article/aws-vs-indian-cloud-providers-data-residency-and-compliance-guide-1786945425596)
- [Mirantis Partners with Pico Public Cloud — BusinessWire](https://www.businesswire.com/news/home/20250807846289/en/Mirantis-Partners-with-Pico-Public-Cloud-to-Launch-First-Sovereign-Public-Cloud-in-Bangladesh)
- [Announcing AWS Outposts rack service in Bangladesh — AWS](https://aws.amazon.com/about-aws/whats-new/2022/11/aws-outposts-rack-service-bangladesh/)

---

## 16. PWA Offline-First Patterns (Low-Bandwidth Context)

**Findings:**
- Service workers underpin every PWA capability (offline support, background sync, push, caching); recommended per-content-type caching strategy: **Cache First** for the app shell, **Network First with cache fallback** for API data, **Stale-While-Revalidate** for images.
- Offline-first is described as a UX philosophy as much as a technical pattern: assume connectivity is unreliable in every flow, show cached data immediately, sync in the background, and clearly communicate sync state ("Saved offline, syncing when connected") rather than a generic spinner.
- IndexedDB (hundreds of MB, async, transactional) is recommended over `localStorage` (~5MB, synchronous) for any offline dataset beyond trivial key/value state.
- Form submissions made offline should be queued in the service worker and flushed via the **Background Sync API** once connectivity returns.

**Applied to this proposal:** This directly informs an expansion of the original PWA section (8): explicit caching-strategy table per asset type, IndexedDB (not localStorage) for offline quiz-attempt/attendance-entry queues, visible "Saved offline — will sync" indicators, and a documented limitation (matching the original proposal's honesty about YouTube-offline limits) that **video content itself is not offline-cacheable** under YouTube's terms — only quiz/attendance/text data is.

**Sources:**
- [Progressive Web Apps 2026: Complete Development Guide — DigitalApplied](https://www.digitalapplied.com/blog/progressive-web-apps-2026-complete-development-guide)
- [How to Build Offline-First Web Apps for Low Bandwidth — IntelliMinds](https://intelliminds.co.ke/insights/building-offline-first-web-applications)
- [Offline-First Web Apps: Resilient PWAs on Flaky Networks](https://nirajiitr.com/blog/offline-first-web-apps-resilient-pwas-flaky-networks)

---

## 17. Video Playback Integrity (YouTube IFrame API Limits)

**Findings:**
- The YouTube IFrame Player API supports progress tracking (play/pause/milestone events, periodic time-polling) but **has no built-in mechanism to prevent seeking/skipping** — confirming the original proposal's own stated limitation (Section 6) is accurate and not overly cautious.
- `enablejsapi=1` is required on the embed, and lazy-loaded players are not tracked unless explicitly initialized — an implementation detail worth noting for the frontend team.

**Applied to this proposal:** No change in direction from the original proposal's honest framing — this research corroborates it rather than overturning it. The expanded spec adds the specific `enablejsapi` implementation requirement and reiterates that **managed streaming (e.g., Bunny Stream with signed/tokenized URLs) is the only way to materially raise the bar**, as a clearly-labeled optional upgrade, not a default.

**Sources:**
- [Tracking YouTube video engagement — Usermaven](https://usermaven.com/docs/youtube-engagement-tracking)
- [YouTube Video Tracking Tag Plugin — Oracle docs](https://docs.oracle.com/en/cloud/saas/marketing/infinity-develop/docs/data_collection/youtube_video_plugin.htm)

---

## 18. Managed Video Streaming Alternatives

**Findings:**
- **Bunny Stream** bundles CDN delivery, transcoding, storage, and a player with **token-authenticated, server-signed playback URLs** — meaning only requests originating from the platform's backend can generate a valid, time-limited playback link. Cited as the lowest-cost option for high-volume video delivery.
- **Mux** is API-first video infrastructure with HLS adaptive streaming and strong DRM support, generally priced higher than Bunny.

**Applied to this proposal:** If the client later needs stronger content protection than public YouTube embeds allow (e.g., licensed/paid training content, not just public videos), recommend **Bunny Stream** as the primary evaluated option (cost-effective, signed URLs) with **Mux** as the alternative where DRM-grade protection is a hard requirement. Kept as an explicitly optional Phase 2+/Phase 3 item, consistent with the original proposal defaulting to YouTube for v1.

**Sources:**
- [Bunny Stream Setup Guide — Bitdoze](https://www.bitdoze.com/bunny-stream-guide/)
- [Mux vs Cloudflare Stream vs Bunny Stream 2026 — PkgPulse](https://www.pkgpulse.com/guides/mux-vs-cloudflare-stream-vs-bunny-stream-video-cdn-2026)

---

## 19. Frontend Framework Choice — React vs Vue

**Findings:**
- React holds a larger market/talent share (cited ~62% of SPAs, ~68% of sampled Fortune 500s) versus Vue (~22%/~12%); both are proven at enterprise scale (React: Meta/Netflix/Airbnb/Stripe; Vue: Alibaba/GitLab/Nintendo).
- For a project needing to staff a team, lean on a broad ecosystem of third-party packages/vendor SDKs, and plan for long-term maintainability by potentially different developers/agencies, React is the more commonly recommended default; Vue is favored for small teams prioritizing fast time-to-market and gentler learning curve, often in incremental-migration scenarios.

**Applied to this proposal:** Since the brief allows either, and this platform is a **multi-year system intended to be maintained/extended by potentially different development teams in Bangladesh over time** (district/national rollout implies longevity and hand-off risk), **React (with TypeScript)** is recommended as the primary choice — matching the original proposal's own stated technology line ("React + TypeScript/PWA") — primarily for the larger local hiring pool and ecosystem depth (chart libraries, PWA tooling, accessibility libraries) rather than a raw performance argument, which research shows is not a significant differentiator between the two for this kind of app.

**Sources:**
- [Vue vs React: Latest Comparison in 2026 — Epicmax](https://epicmax.co/blog/react-vue)
- [React vs Vue: What's the Better Choice in 2026? — MindInventory](https://www.mindinventory.com/blog/reactjs-vs-vuejs/)
- [React vs Angular vs Vue vs Ext JS: Enterprise 2026 — PCTechMag](https://pctechmag.com/2026/05/javascript-framework-comparison-react-vs-angular-vs-vue-vs-ext-js-which-one-wins-for-enterprise-in-2026/)

---

## 20. Automated Timetable Generation (Smart Class Timetable feature)

**Findings:**
- School/university timetabling is a well-studied **NP-complete constraint satisfaction problem**; standard solving approaches are CSP solvers with backtracking + constraint propagation, sometimes hybridized with Genetic Algorithms for optimization once hard constraints (no teacher double-booked, room capacity, etc.) are satisfied.
- Cited practical benchmark: a CSP-based system can generate a complete, conflict-free timetable **in under 5 minutes** for a school with up to ~60 teachers and ~30 classes, reducing manual scheduling time by a cited ~40% versus hand scheduling.

**Applied to this proposal:** Confirms the original proposal's "Smart Class Timetable" feature (Section 5.6) is technically well-precedented and achievable within the proposed timeline using a **CSP-based solver** (a PHP constraint-solving approach or a dedicated solver library/service called from a queued Laravel job, given generation can take real seconds-to-minutes and must not block a web request). Recommends explicitly scoping *which* constraints are "hard" (must never violate — teacher double-booking, room capacity) versus "soft" (preferred but can be relaxed — e.g., minimizing teacher gaps) during requirements analysis, since that distinction drives solver design.

**Sources:**
- [Dynamic Timetable Generation Using Constraint Satisfaction Algorithm — Springer](https://link.springer.com/chapter/10.1007/978-81-322-2517-1_73)
- [Automated Class Scheduling Optimization — IJSDR](https://ijsdr.org/papers/IJSDR2603203.pdf)
- [An Algorithm to Automatically Generate Schedule for School Lectures — ResearchGate](https://www.researchgate.net/publication/271297445_An_Algorithm_to_Automatically_Generate_Schedule_for_School_Lectures_Using_a_Heuristic_Approach)

---

## 21. Accessibility (WCAG)

**Findings:**
- WCAG **2.1 AA** is the current baseline legal reference standard cited for public-sector/education web content internationally (via the U.S. DOJ Title II rule as one concrete example), but **WCAG 2.2 AA** is the current full spec and the recommended forward-looking target since it adds criteria for focus visibility, drag-interaction alternatives, accessible authentication, and larger touch targets — all directly relevant to a mobile-first PWA used on low-end Android devices.
- Coverage scope explicitly includes LMS content, student portals, and electronic documents/certificates — not just marketing pages.

**Applied to this proposal:** Upgrades the original proposal's accessibility language ("readable fonts, sufficient color contrast, keyboard support, screen-reader considerations," Section 8) into an explicit **WCAG 2.2 AA conformance target**, called out as a testable acceptance criterion rather than a general intention, given the platform is likely to be used in government/public-school contexts where this is increasingly a procurement requirement, not just good practice.

**Sources:**
- [WCAG Compliance 2026: Standards and Requirements — Level Access](https://www.levelaccess.com/compliance-overview/wcag-web-content-accessibility-guidelines/)
- [Web Accessibility for Educational Institutions (2026 Guide) — WebAbility](https://www.webability.io/blog/web-accessibility-for-educational-institutions)

---

## 22. Development Cost Benchmarks (Bangladesh)

**Findings:**
- Local Bangladeshi developer hourly rates: **~$15–25/hr junior, $25–40/hr mid-level, $35–60/hr senior/agency** — broadly $15–35/hr for mainstream web development, versus $80–150+/hr for comparable work in the US/Europe.
- Project-based reference points: a simple web app (login + dashboard + DB-driven features) **$3,000–$10,000**; a mid-complexity multi-role app with payments and API integrations **$10,000–$30,000**; full-featured systems citing a range up to **$50,000**.
- A 5-person dedicated team (2 developers, 1 QA, 1 DevOps, 1 tech lead) in South Asia is cited at **$18,000–$30,000/month**.

**Applied to this proposal:** This platform is materially larger in scope than the "mid-complexity" benchmark (it spans training/LMS, assessment, AI integration, and a 14-module school ERP) — the Cost Considerations section in the proposal uses the **5-person dedicated team benchmark** ($18–30k/month) as the basis for a phase-by-phase labor estimate, converted to BDT at the ~123 BDT/USD reference rate, rather than the simpler per-project flat-fee ranges which understate a system of this scope.

**Sources:**
- [Offshore Software Development Rates by Country 2026 — Algoscale](https://algoscale.com/blog/offshore-software-development-rates/)
- [Custom Software Development Cost in Bangladesh 2026 — Dcastalia](https://dcastalia.com/blog/custom-software-development-cost-in-bangladesh/)
- [Bangladesh as a Software Outsourcing Hub 2026 — DS Innovators](https://www.dsinnovators.com/blog/engineering-leadership/bangladesh-software-outsourcing-hub-2026/)

---

## Summary of How This Research Changed the Proposal

| Area | Original proposal | Key research-backed change |
|---|---|---|
| Backend structure | "Laravel only," unspecified internal structure | Modular monolith by domain; documented multi-tenancy pattern and upgrade path |
| Auth | Not specified | Sanctum (cookie-based SPA auth), not Passport |
| API | Not specified | Versioned `/api/v1`, mandatory API Resources for role-safe serialization |
| AI vendor | "AI service accessed securely by Laravel" (unnamed) | OpenAI GPT-4o-mini as primary, with Batch API + Prompt Caching for cost control; Bangla-specific transcription via a dedicated Bangla ASR model rather than assuming Whisper handles Bangla equally well |
| Payments | Fee module described functionally, no vendor named | bKash/Nagad via `laravel-bdpaygate` or SSLCommerz aggregator, server-side confirmation required |
| SMS/notifications | Not present | New: abstracted SMS gateway layer + parent notification feature |
| Data protection | General principles stated | Explicit tracking of Bangladesh's still-draft DPA as a live risk; FERPA/COPPA-equivalent practices adopted voluntarily now |
| Hosting | Not specified | AWS Mumbai default for pilot; sovereign/local Bangladesh hosting flagged as a required evaluation before national rollout |
| Accessibility | General language | Explicit WCAG 2.2 AA conformance target with testable criteria |
| Scaling | Not specified | Concrete, trigger-based path: cache → read replica → shard, not built upfront |
| Cost | Deferred entirely ("prepared after approval") | Full BDT/USD estimate per phase, grounded in Bangladesh market-rate benchmarks |
