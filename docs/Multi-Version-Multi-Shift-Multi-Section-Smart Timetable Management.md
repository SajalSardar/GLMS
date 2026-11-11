# Multi-Version, Multi-Shift, Multi-Section & Smart Timetable Management

You are a Senior Laravel Software Architect, Database Designer, and Full-Stack Developer working on my existing **AI-Powered Teacher Training, Student Learning, and School Management Platform**.

## Project Directory

`D:\laragon\www\Training_Learning_Analytics_Platform`

# Phase 1: Analyze the Existing Project

Before writing or modifying code:

1. Inspect the existing project structure, Laravel version, frontend framework, authentication, authorization, database schema, and coding conventions.
2. Read the original project requirements document if available:
   `D:\laragon\www\Training_Learning_Analytics_Platform\inspiration\Training_Learning_Analytics_Proposal.docx`
3. Inspect existing school, academic year, class, section, subject, teacher, classroom, timetable, and student enrollment implementations.
4. Identify existing database tables, migrations, models, controllers, services, API routes, frontend pages, and relationships.
5. Identify reusable components and existing CRUD patterns.
6. Prepare a short implementation plan identifying what can be reused, what needs modification, and what needs to be created.

**Important:** Do not create duplicate tables or modules if equivalent functionality already exists. Extend the current architecture safely and preserve existing data.

# Phase 2: Implement Academic Structure Configuration

Implement a flexible academic structure that supports multiple versions, shifts, sections, classrooms, teachers, and timetables within the same school.

The hierarchy should support:

School → Academic Year → Class → Version/Curriculum → Shift → Section → Classroom Assignment → Timetable

The hierarchy must be configurable rather than hardcoded.

## 2.1 School and Academic Year

Each configuration must belong to the appropriate school and academic year.

Requirements:

- Support multiple schools and campuses if the existing architecture supports them.
- Configure academic years and terms.
- Activate or archive academic configurations by academic year.
- Preserve historical section assignments and timetables.
- Prevent records from different schools from being mixed.

## 2.2 Class Configuration

Allow administrators to configure classes such as:

- Play
- Nursery
- KG
- Class 1 through Class 12

Class names and numbering must be configurable.

Example:

- Class 6
- Class 7
- Class 8

Each class can have multiple versions.

## 2.3 Version / Curriculum Configuration

Support multiple educational versions within the same class.

Example:

Class 6:

- Bangla Version
- English Version

Class 7:

- Bangla Version
- English Version

Requirements:

- Create, edit, activate, and archive versions.
- Configure version-specific subjects and curricula.
- Assign the appropriate subject teachers.
- Prevent invalid combinations between classes, versions, and sections.
- Do not assume that every school must have both versions.

## 2.4 Shift Configuration

Allow multiple shifts for each class and version.

Example:

Class 6 → Bangla Version:

- Morning Shift
- Day Shift
- Night Shift

Class 6 → English Version:

- Morning Shift
- Day Shift

Each shift should support:

- Shift name
- Start time and end time
- Applicable school days
- Break intervals
- Period duration or configurable period templates
- Applicable academic year
- Active/inactive status

Allow schools to configure their own shift names and times.

Do not globally hardcode Morning, Day, or Night. These are example values.

Validate that a shift has a valid time range and that its associated timetable follows the school's scheduling rules.

## 2.5 Section Configuration

Each shift can contain multiple sections with configurable names.

Example:

Class 6 → Bangla Version → Morning Shift:

- Section A
- Section B
- Section C

Another school might use:

- Section I
- Section II
- Section III

Or custom names such as:

- Bulbuli
- Moina
- Doel

Requirements:

- Support arbitrary section names.
- Allow section codes, display names, capacity, and status.
- Configure maximum student capacity.
- Assign a class teacher to each section.
- Assign students to sections for a specific academic year.
- Transfer students between sections with proper history.
- Prevent duplicate enrollment in the same applicable academic configuration.
- Prevent assignments that violate section capacity unless an authorized override is supported.

Section names must never be restricted to A/B/C or I/II/III.

# Phase 3: Teacher and Subject Assignment

Implement a complete teacher assignment workflow.

## 3.1 Teacher Management

Use existing teacher records.

Each teacher should be assignable to:

- One or more classes
- One or more versions, where qualified
- One or more sections
- One or more subjects
- Specific periods and days

Do not create duplicate teacher profiles.

## 3.2 Section Teacher Assignment

Allow administrators to assign a class teacher to a section.

Example:

Class 6 → Bangla Version → Morning Shift → Section A

Class Teacher: Teacher A

Allow additional subject teachers to be assigned independently.

## 3.3 Subject Teacher Assignment

Example:

| Class   | Version | Shift   | Section | Subject     | Teacher   |
| ------- | ------- | ------- | ------- | ----------- | --------- |
| Class 6 | Bangla  | Morning | A       | Bangla      | Teacher A |
| Class 6 | Bangla  | Morning | A       | Mathematics | Teacher B |
| Class 6 | Bangla  | Morning | A       | English     | Teacher C |
| Class 6 | Bangla  | Morning | B       | Mathematics | Teacher D |
| Class 6 | English | Morning | A       | Mathematics | Teacher E |

Requirements:

- A teacher may teach the same subject in multiple sections.
- A section may have different teachers for different subjects.
- A teacher may teach different subjects only when the assignment is authorized.
- Configure teacher availability and maximum teaching periods if required.
- Prevent conflicting assignments during overlapping periods.
- Preserve assignment history by academic year.

# Phase 4: Classroom Configuration and Assignment

Implement classroom management and section-to-classroom assignment.

## 4.1 Classroom Fields

Support:

- Classroom name
- Classroom code
- Campus or building
- Floor or location
- Capacity
- Room type
- Available equipment
- Availability status

Example:

- Room 101 — Capacity 40
- Room 102 — Capacity 35
- Science Lab — Capacity 30

## 4.2 Section and Classroom Assignment

Example:

| Class   | Version | Shift   | Section | Classroom |
| ------- | ------- | ------- | ------- | --------- |
| Class 6 | Bangla  | Morning | A       | Room 101  |
| Class 6 | Bangla  | Morning | B       | Room 102  |
| Class 6 | English | Morning | A       | Room 103  |
| Class 6 | Bangla  | Day     | A       | Room 101  |

Requirements:

- Allow classroom assignment by section and academic year.
- Support permanent default classrooms and period-specific room assignments.
- Ensure the classroom capacity is compatible with the number of assigned students.
- Prevent the same classroom from being allocated to overlapping classes.
- Allow authorized room changes with history.
- Support laboratories and specialist rooms.
- Account for breaks, room availability, and school operating hours.

A classroom may be reused by different sections at different times, provided their schedules do not overlap.

# Phase 5: Timetable / Routine Generation

Build a complete timetable management system with both automatic generation and manual editing.

## 5.1 Timetable Configuration

Allow administrators to configure:

- Academic year and term
- Class, version, shift, and section
- School working days
- Period start and end times
- Number of periods per day
- Period duration
- Break and lunch intervals
- Subjects and required weekly periods
- Assigned teachers
- Assigned classrooms
- Teacher availability
- Classroom availability
- Maximum consecutive periods
- Subject-specific scheduling constraints

The system must generate routines using the configured values instead of hardcoded assumptions.

## 5.2 Automatic Routine Generation

Provide an action such as:

**Generate Timetable**

The system should generate a timetable for a selected:

- Academic year
- Class
- Version
- Shift
- Section
- Selected group of sections, or the entire school

The generation engine must satisfy the following hard constraints:

1. A teacher cannot teach two classes during the same time slot.
2. A classroom cannot host two classes during the same time slot.
3. A section cannot have two subjects during the same time slot.
4. Subjects must meet their required weekly period counts.
5. Classes must follow their configured shift and school operating hours.
6. Lessons must not overlap configured breaks.
7. Teachers must be available during their assigned periods.
8. Room capacity must satisfy the relevant section's requirements.
9. A teacher cannot be assigned to a subject or section without a valid assignment.
10. Existing approved timetable entries must not be overwritten silently.

Soft constraints should improve the quality of the generated timetable:

- Distribute subjects across the week.
- Avoid excessive consecutive periods for a teacher.
- Avoid scheduling the same subject repeatedly on the same day when possible.
- Prefer balanced teacher workloads.
- Respect preferred periods and room preferences.
- Avoid unnecessary gaps in teacher schedules.
- Support configurable preferences for difficult subjects earlier in the day.

If the timetable cannot be generated, return a clear conflict report instead of producing an invalid routine.

Do not claim that a timetable is valid unless all hard constraints have been checked.

## 5.3 Manual Timetable Editor

Provide a visual timetable editor.

Features:

- Weekly grid view
- Section-based timetable
- Teacher-based timetable
- Classroom-based timetable
- Drag-and-drop scheduling if supported by the existing frontend
- Add, edit, move, and delete a period
- Real-time conflict validation
- Display subject, teacher, room, and period time
- Show conflicts before saving
- Save drafts and publish approved routines
- Print and export to PDF/Excel

## 5.4 Routine Publication

Timetable workflow:

Draft → Validation → Approval (if required) → Published

Requirements:

- Teachers can view their assigned schedules.
- Students can view their section's schedule.
- Parents can view their children's schedules.
- Administrators can publish and revise schedules.
- Notify affected users when a published timetable changes.
- Preserve historical published versions.
- Prevent unauthorized edits to published routines.
