# Open Questions

Items that need a decision from the client/education authority or from the project owner before (or during) later phases. Split into what the **original proposal itself** flagged as unresolved, and what this initialization additionally surfaced.

## 1. From the Original Proposal (🔹 ORIGINAL, docx §15 "Assumptions and Items to Confirm")

- Does the initial rollout cover one school, multiple schools, a district, or national operations?
- What are the existing data sources, identity-verification requirements, and import formats (for bulk CSV/XLSX import of students/teachers)?
- Is permission secured to embed the specific required YouTube videos?
- AI, video hosting, cloud, SMS/email, and third-party licensing costs may be billed separately — confirm what's in scope.
- The education authority must approve: the competency scoring weights/bands (Section 7's weights are explicitly illustrative only), A/B/C/D grading thresholds, the 85% attendance condition (and its calculation source/period/exceptions), data retention policy, and access policies.
- Commercial pricing, payment milestones, service levels, hosting, and post-launch support — to be defined in the contract, not a technical decision.
- Sample designs for A4 admit cards, 16 seat-level cards/labels per page, Word/PDF print output — need approval before building the print templates.

## 2. From the Expanded Research (🆕, `inspiration/AI_Teacher_Training_Proposal_v2.md` §20)

- Current status of the **Bangladesh Data Protection Act** (still draft as of the source research) — must be re-confirmed before go-live, since it may be enacted mid-project.
- Is **AWS Mumbai (ap-south-1)** hosting acceptable for the pilot, or does a local/sovereign hosting requirement already apply (e.g., a government-run pilot)?
- Is the **Parent/Guardian communication module** (and the other optional modules: Library, Staff HR/Payroll) in scope for this engagement, or deferred?
- Which **mobile wallets** are actually required — bKash and Nagad are assumed as priority, but this should be confirmed with the schools/authority.
- Is **NCTB competency-code mapping** available/required at launch, or can it be phased in?
- Who are the named **Trainers/Content Reviewers** responsible for approving AI-drafted content, and what is their expected turnaround time?

## 3. Surfaced During This Initialization (new)

- **Inspiration images**: five WhatsApp screenshots exist in `inspiration/` (`WhatsApp Image 2026-10-08 at *.jpeg`) that were not reviewed/catalogued as part of this setup — confirm whether they contain additional requirements, mockups, or reference material that should be cross-checked against the written proposal before Phase 1 planning for later modules.
- **AI vendor credentials**: no OpenAI (or any AI vendor) API key exists anywhere in this repository, by design — the `AiProviderInterface` abstraction means the actual key/provider decision can be deferred to whenever Phase 2 AI work starts, configured via environment variables only.
- **Laragon vhost vs. `artisan serve`**: Phase 1 documents `php artisan serve` as the local backend URL (`http://127.0.0.1:8000`) rather than a Laragon `.test` virtual host, since the Laravel document root (`backend/public/`) sits one level below the project folder Laragon auto-maps. Confirm if a `.test` domain is preferred for local development — it would need a manually configured Laragon vhost pointing at `backend/public`.
- **Orphaned file**: `~$acher_Training_Learning_Analytics_Proposal_with_mobile_app.docx` exists at the project root — it is a Word lock/temp file (not real content) referencing a differently-named document ("...with_mobile_app.docx") that is not otherwise present in this project. It was left untouched; confirm whether a mobile-app variant of the proposal exists elsewhere and should be added to `inspiration/`, since the current proposal explicitly scopes out a native mobile app in favor of a PWA.
- **Exact installed framework versions**: recorded in `ARCHITECTURE.md` based on what Composer/npm actually resolved during setup — reconfirm against `backend/composer.json` and `frontend/package.json` before relying on a specific version number in any future planning.
