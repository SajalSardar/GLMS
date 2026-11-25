# Question Builder — Design Spec

## Overview

Teachers build printable question papers by searching a national question
bank (filtered by class, subject, chapter, type, marks), dragging matching
questions onto an ordered canvas, inserting section headings between groups
of questions, and saving the result. Saved papers can be printed by the
creating teacher, any teacher at the same school, or that school's
school admins. The question bank itself — individual CQ/MCQ questions — is
managed exclusively by `national_admin` through the management portal.

## Goals

- A global, national question bank (`academic_questions`) categorized by
  class, subject, and chapter, holding CQ and MCQ questions.
- A teacher-facing builder: filter the bank, drag questions onto a layout,
  insert headings, save as a `question_paper`.
- Print access for the creating teacher, same-school colleague teachers,
  and same-school `school_admin`s — edit/delete restricted to the creator.
- `national_admin`-only CRUD on the question bank.

## Non-Goals (v1)

- No answer-key storage (no "correct option" flag on MCQ, no CQ model
  answers) — question content only, per explicit decision.
- No academic-year scoping on the bank — class/subject/chapter curriculum
  structure is treated as perennial; a paper's own year (if any) is just
  free text the teacher types into its title.
- No structured sub-question modeling for CQ's ক/খ/গ/ঘ parts — the whole
  creative-question block (stimulus + sub-parts) is one authored text
  field for v1.
- No PDF generation library — printing is `window.print()` on a dedicated
  print view, matching the existing `MarksheetsPage` pattern.

## Data Model

### `academic_questions` (the national bank — global, not tenant-scoped)

| Column | Type | Notes |
|---|---|---|
| `school_class_id` | FK → `school_classes.id` | global class reference |
| `subject_id` | FK → `subjects.id` | global subject reference |
| `chapter_number` | unsigned int | plain denormalized field — **not** a FK into the existing per-school `chapters` table, which is reached through `Book → ClassVersion → school` and would force per-school duplication |
| `chapter_name` | string | denormalized alongside `chapter_number` |
| `question_type` | string, `cq`\|`mcq` | validated at the FormRequest, not a DB enum (matches this app's existing string-status convention, e.g. `TeacherLeaveApplication::STATUSES`) |
| `question_text` | text | CQ: the full stimulus + ক/খ/গ/ঘ sub-parts as one authored block. MCQ: the stem. |
| `options` | json, nullable | MCQ only — four option texts, no correct-answer marker |
| `marks` | unsigned int | |
| `is_active` | boolean, default true | |

Plus `Auditable` (polymorphic `created_by`/`updated_by`), `SoftDeletes`,
timestamps. No `school_id`/`district_id` — this table is intentionally
global; `national_admin` is the only role with write access.

Indexes: `[school_class_id, subject_id, chapter_number]`, `[question_type]`.

### `question_papers` (a teacher's saved, built paper)

| Column | Type | Notes |
|---|---|---|
| `teacher_id` | FK → `teacher_users.id` | creator |
| `school_id` | FK → `schools.id` | denormalized from the creator at creation time — drives tenant scoping |
| `title` | string | |
| `school_class_id` | FK → `school_classes.id` | what the paper was built for |
| `subject_id` | FK → `subjects.id` | |
| `time_duration_minutes` | unsigned int, nullable | header metadata (sample papers reviewed show this is standard) |
| `full_marks` | unsigned int, nullable | auto-summed from items client-side, stored value is editable by the teacher |
| `instructions` | text, nullable | general direction text, e.g. "সকল প্রশ্নের মান সমান" |

Plus `Auditable`, `SoftDeletes`, timestamps, and the standard `TenantScoped`
trait — `TenantScope`'s existing actor-school_id scoping already produces
exactly the desired visibility (same-school teachers and that school's
admins see it; `national_admin` sees everything; cross-school is
invisible) with no custom scope needed. The Policy layers the
creator-only restriction for `update`/`delete` on top of that.

Indexes: `[school_id]`, `[teacher_id]`.

### `question_paper_items` (ordered drag-and-drop content)

| Column | Type | Notes |
|---|---|---|
| `question_paper_id` | FK → `question_papers.id`, cascade delete | |
| `item_type` | string, `question`\|`heading` | |
| `academic_question_id` | FK → `academic_questions.id`, nullable | set only when `item_type = question` |
| `heading_text` | string, nullable | set only when `item_type = heading` |
| `order_index` | unsigned int | drag-and-drop position |

No Auditable/SoftDeletes — items are fully replaced as a set on every
paper update (see API below), so per-row history isn't meaningful.

Index: `[question_paper_id, order_index]`.

## Permissions

| Permission | Granted to | Enforced by |
|---|---|---|
| `academic_questions.view` | `teacher`, `national_admin` | Teachers browse the bank to build papers. `school_admin` never needs raw bank access — they only print already-saved papers. |
| `academic_questions.create/update/delete` | `national_admin` only | |
| `question_papers.view` | `teacher`, `school_admin` | `TenantScope` (same school visibility) + Policy |
| `question_papers.create` | `teacher` | Policy checks actor is a `TeacherUser` with an assigned school |
| `question_papers.update`/`delete` | `teacher` | Policy additionally restricts to `teacher_id === actor->id` — creator only, even though colleagues can view/print |

`school_admin` never gets `question_papers.create/update/delete` —
print-only, per the spec's explicit requirement.

## API Endpoints

### Question bank — `/api/v1/academic-questions`

- `GET /` — filter by `school_class_id`, `subject_id`, `chapter_number`,
  `question_type`, `marks`. `teacher`, `national_admin`.
- `POST /`, `PUT /{id}`, `DELETE /{id}` — `national_admin` only.

### Question papers — `/api/v1/question-papers`

- `GET /` — `TenantScope`-filtered list (teacher: own school's papers;
  school_admin: own school's papers; national_admin: all).
- `POST /` — body: `title`, header fields, `school_class_id`,
  `subject_id`, and an ordered `items` array, each either
  `{type: 'question', academic_question_id}` or
  `{type: 'heading', heading_text}`. Validates every
  `academic_question_id` exists and `is_active`. `teacher` only.
- `GET /{id}` — same visibility as list.
- `PUT /{id}` — creator only. Replaces the full item set (simplest
  correct semantics for a drag-and-drop canvas — no partial-patch
  complexity).
- `DELETE /{id}` — creator only (soft delete).

Printing is not a backend endpoint — `GET /{id}` feeds a client-side print
view (`window.print()`).

## Frontend

### Management portal — "Question Bank" page (new, `national_admin` only)

`GeoCrudTable`-style page gated with
`createPermission="academic_questions.create"` etc. (same mechanism as
`AllChaptersPage`). Form: cascading class → subject selects, chapter
number + name, question-type toggle (CQ/MCQ changes the fields below it),
marks; MCQ reveals four option text inputs.

### Teacher portal — "Question Builder" page (new)

- Left panel: filter criteria (class/subject/chapter/type/marks) → "Load
  Questions" → scrollable list of matching bank questions.
- Right panel: the paper canvas — a drop target where questions land in
  order; a toolbar action inserts a heading block at a chosen position;
  items are reorderable by drag.
- New dependency: `@dnd-kit/core` + `@dnd-kit/sortable`, scoped to
  `apps/teacher` — no drag-and-drop library exists in this codebase today
  (only native HTML5 drag/drop for file uploads in `ClassMaterialsPage`),
  and dnd-kit is the modern, accessible standard for sortable-list UIs.
- Header fields form: title, time duration, instructions; full marks
  auto-sums from items but stays editable.
- Save → `POST /question-papers`.

### Teacher portal — "My Question Papers" page (new)

Lists own + same-school papers (via `TenantScope`). Each row: Print
button (own and colleagues'), Edit/Delete only on own.

### Management portal — "Question Papers" page (new, `school_admin` only)

Lists all papers at their school (via `TenantScope`). Print button only —
no edit/delete.

### Print view (shared component)

Renders: title + class/subject, time/marks/instructions header, then
items in order — headings as section dividers, questions numbered
continuously, MCQ options as ক)/খ)/গ)/ঘ) — triggered by `window.print()`,
matching `MarksheetsPage`'s existing pattern. No dedicated print
stylesheet exists yet in this codebase; one may be added if the default
browser print rendering of the canvas layout needs cleanup.

## Testing Strategy

- Backend: Policy tests (national_admin-only bank CRUD; teacher/
  school_admin `TenantScope` visibility and creator-only edit/delete for
  papers), a permission-mapping drift-guard test, controller tests for
  bank filter criteria and the items-replace-on-update semantics.
- Frontend: component tests for the builder's add/remove/reorder/
  heading-insert logic; `tsc`/vitest parity across all three apps and the
  shared i18n package.
- No data migration concerns — entirely new tables, nothing to backfill.

## Resolved Design Decisions

1. **Bank scope:** fully global pool (class/subject/chapter denormalized,
   not tied to any school's existing per-school `Chapter` rows) — every
   teacher nationwide searches the same bank.
2. **Print/view access:** creator teacher + any teacher at the same school
   + that school's school_admins. Edit/delete remains creator-only.
3. **Answer keys:** question content only — no correct-answer storage in
   v1.
4. **Paper header:** full exam-header metadata (time duration, full
   marks, instructions) — confirmed against sample exam-paper images
   showing this is the standard format, overriding the initially-proposed
   leaner option.
