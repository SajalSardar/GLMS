<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Permission catalog covers every resource the API currently exposes.
     * New modules (courses, attendance, results, fees, ...) add their own
     * permissions here as those APIs are built in later phases.
     */
    public function run(): void
    {
        $permissions = [
            ['name' => 'divisions.view', 'label' => 'View divisions'],
            ['name' => 'divisions.create', 'label' => 'Create divisions'],
            ['name' => 'divisions.update', 'label' => 'Update divisions'],
            ['name' => 'divisions.delete', 'label' => 'Delete divisions'],

            ['name' => 'districts.view', 'label' => 'View districts'],
            ['name' => 'districts.create', 'label' => 'Create districts'],
            ['name' => 'districts.update', 'label' => 'Update districts'],
            ['name' => 'districts.delete', 'label' => 'Delete districts'],

            ['name' => 'upazilas.view', 'label' => 'View upazilas'],
            ['name' => 'upazilas.create', 'label' => 'Create upazilas'],
            ['name' => 'upazilas.update', 'label' => 'Update upazilas'],
            ['name' => 'upazilas.delete', 'label' => 'Delete upazilas'],

            ['name' => 'schools.view', 'label' => 'View schools'],
            ['name' => 'schools.create', 'label' => 'Create schools'],
            ['name' => 'schools.update', 'label' => 'Update schools'],
            ['name' => 'schools.delete', 'label' => 'Delete schools'],

            ['name' => 'classes.view', 'label' => 'View classes'],
            ['name' => 'classes.create', 'label' => 'Create classes'],
            ['name' => 'classes.update', 'label' => 'Update classes'],
            ['name' => 'classes.delete', 'label' => 'Delete classes'],

            ['name' => 'class_schools.view', 'label' => 'View class-school assignments'],
            ['name' => 'class_schools.create', 'label' => 'Assign a class to a school'],
            ['name' => 'class_schools.delete', 'label' => 'Unassign a class from a school'],
            ['name' => 'class_schools.updateSubjects', 'label' => 'Configure a school\'s subject list for a class'],

            ['name' => 'subjects.view', 'label' => 'View subjects'],
            ['name' => 'subjects.create', 'label' => 'Create subjects'],
            ['name' => 'subjects.update', 'label' => 'Update subjects'],
            ['name' => 'subjects.delete', 'label' => 'Delete subjects'],

            ['name' => 'books.view', 'label' => 'View books'],
            ['name' => 'books.create', 'label' => 'Create books'],
            ['name' => 'books.update', 'label' => 'Update books'],
            ['name' => 'books.delete', 'label' => 'Delete books'],

            ['name' => 'chapter_materials.view', 'label' => 'View chapter materials'],
            ['name' => 'chapter_materials.create', 'label' => 'Create chapter materials'],
            ['name' => 'chapter_materials.update', 'label' => 'Update chapter materials'],
            ['name' => 'chapter_materials.delete', 'label' => 'Delete chapter materials'],

            ['name' => 'chapter_videos.view', 'label' => 'View chapter videos'],
            ['name' => 'chapter_videos.create', 'label' => 'Create chapter videos'],
            ['name' => 'chapter_videos.update', 'label' => 'Update chapter videos'],
            ['name' => 'chapter_videos.delete', 'label' => 'Delete chapter videos'],

            ['name' => 'chapters.view', 'label' => 'View chapters'],
            ['name' => 'chapters.create', 'label' => 'Create chapters'],
            ['name' => 'chapters.update', 'label' => 'Update chapters'],
            ['name' => 'chapters.delete', 'label' => 'Delete chapters'],

            ['name' => 'questions.view', 'label' => 'View questions'],
            ['name' => 'questions.create', 'label' => 'Create questions'],
            ['name' => 'questions.update', 'label' => 'Update questions'],
            ['name' => 'questions.delete', 'label' => 'Delete questions'],

            ['name' => 'exams.view', 'label' => 'View exams'],
            ['name' => 'exams.create', 'label' => 'Create exams'],
            ['name' => 'exams.update', 'label' => 'Update exams'],
            ['name' => 'exams.delete', 'label' => 'Delete exams'],

            ['name' => 'exam_attempts.view', 'label' => 'View any exam attempt'],
            ['name' => 'exam_attempts.create', 'label' => 'Start, answer, and submit exam attempts'],

            ['name' => 'student_progress.view', 'label' => 'View any student\'s learning dashboard'],

            ['name' => 'users.view', 'label' => 'View users'],
            ['name' => 'users.create', 'label' => 'Create users'],
            ['name' => 'users.update', 'label' => 'Update users'],
            ['name' => 'users.delete', 'label' => 'Delete users'],

            ['name' => 'roles.view', 'label' => 'View roles'],
            ['name' => 'roles.create', 'label' => 'Create roles'],
            ['name' => 'roles.update', 'label' => 'Update roles'],
            ['name' => 'roles.delete', 'label' => 'Delete roles'],

            ['name' => 'permissions.view', 'label' => 'View permissions'],

            ['name' => 'teacher_profiles.view', 'label' => 'View teacher profiles'],
            ['name' => 'teacher_profiles.create', 'label' => 'Create teacher profiles'],
            ['name' => 'teacher_profiles.update', 'label' => 'Update teacher profiles'],
            ['name' => 'teacher_profiles.delete', 'label' => 'Delete teacher profiles'],

            ['name' => 'student_profiles.view', 'label' => 'View student profiles'],
            ['name' => 'student_profiles.create', 'label' => 'Create student profiles'],
            ['name' => 'student_profiles.update', 'label' => 'Update student profiles'],
            ['name' => 'student_profiles.delete', 'label' => 'Delete student profiles'],

            // A student submits their own leave application outside this
            // permission system entirely (StoreStudentLeaveApplicationRequest
            // just checks the actor is a StudentUser, same as how
            // chapter-materials/mine and exam attempt creation bypass
            // Role/Permission) — these two only gate a staff/teacher
            // actor's ability to review other students' applications.
            ['name' => 'student_leave_applications.view', 'label' => 'View student leave applications'],
            ['name' => 'student_leave_applications.update', 'label' => 'Review student leave applications'],

            // A teacher submits their own leave application and names a
            // substitute colleague outside this permission system
            // entirely (StoreTeacherLeaveApplicationRequest just checks
            // the actor is a TeacherUser) — these two only gate a staff
            // actor's ability to list/review every teacher's application.
            // Unlike student_leave_applications.update, reviewing here
            // has no additional per-row ownership check: the reviewer is
            // always school_admin, scoped only by TenantScope.
            ['name' => 'teacher_leave_applications.view', 'label' => 'View teacher leave applications'],
            ['name' => 'teacher_leave_applications.update', 'label' => 'Review teacher leave applications'],

            // The national question bank — categorized by class, subject,
            // and chapter. Only national_admin ever gets create/update/
            // delete (see RolePermissionSeeder); teacher gets .view to
            // browse it when building a question paper. school_admin gets
            // neither — it never browses the raw bank, only prints
            // already-saved question papers (see question_papers.* below).
            ['name' => 'academic_questions.view', 'label' => 'View the academic question bank'],
            ['name' => 'academic_questions.create', 'label' => 'Create academic questions'],
            ['name' => 'academic_questions.update', 'label' => 'Update academic questions'],
            ['name' => 'academic_questions.delete', 'label' => 'Delete academic questions'],

            // The global chapter list backing the question bank's chapter
            // picker — same national_admin-only CRUD tier as the bank
            // itself; teacher gets .view to populate the picker when
            // filtering/building a paper.
            ['name' => 'academic_question_chapters.view', 'label' => 'View the question bank chapter list'],
            ['name' => 'academic_question_chapters.create', 'label' => 'Create question bank chapters'],
            ['name' => 'academic_question_chapters.update', 'label' => 'Update question bank chapters'],
            ['name' => 'academic_question_chapters.delete', 'label' => 'Delete question bank chapters'],

            // A teacher's saved, built question paper. .create is teacher-
            // only; .update/.delete are also teacher-only but additionally
            // gated to the paper's own creator by QuestionPaperPolicy — a
            // colleague at the same school can view/print but never edit.
            // school_admin gets .view only (print-only, per the feature's
            // explicit requirement) and never .create/.update/.delete.
            ['name' => 'question_papers.view', 'label' => 'View question papers'],
            ['name' => 'question_papers.create', 'label' => 'Create question papers'],
            ['name' => 'question_papers.update', 'label' => 'Update a question paper (creator only)'],
            ['name' => 'question_papers.delete', 'label' => 'Delete a question paper (creator only)'],

            // A student's own attendance is self-scoped outside this
            // permission system (AttendanceController::mine() just checks
            // the actor is a StudentUser, same as leave applications'
            // mine()). .view/.update here only gate a staff actor's
            // broader access — a teacher additionally needs
            // AttendancePolicy::recordSection() (the section's own class
            // teacher) to actually record, same convention as
            // student_leave_applications.update + the review() policy.
            ['name' => 'attendances.view', 'label' => 'View attendance reports'],
            ['name' => 'attendances.update', 'label' => 'Record or update attendance'],

            ['name' => 'guardians.view', 'label' => 'View guardians'],
            ['name' => 'guardians.create', 'label' => 'Create guardians'],
            ['name' => 'guardians.update', 'label' => 'Update guardians'],
            ['name' => 'guardians.delete', 'label' => 'Delete guardians'],

            ['name' => 'academic_years.view', 'label' => 'View academic years'],
            ['name' => 'academic_years.create', 'label' => 'Create academic years'],
            ['name' => 'academic_years.update', 'label' => 'Update academic years'],
            ['name' => 'academic_years.delete', 'label' => 'Delete academic years'],

            // The academic holiday calendar is national, not per-school —
            // .create/.update/.delete are only ever granted to
            // national_admin (see RolePermissionSeeder); every other
            // role gets .view only.
            ['name' => 'holidays.view', 'label' => 'View the holiday calendar'],
            ['name' => 'holidays.create', 'label' => 'Create holidays'],
            ['name' => 'holidays.update', 'label' => 'Update holidays'],
            ['name' => 'holidays.delete', 'label' => 'Delete holidays'],

            ['name' => 'classrooms.view', 'label' => 'View classrooms'],
            ['name' => 'classrooms.create', 'label' => 'Create classrooms'],
            ['name' => 'classrooms.update', 'label' => 'Update classrooms'],
            ['name' => 'classrooms.delete', 'label' => 'Delete classrooms'],

            ['name' => 'class_routines.view', 'label' => 'View class routines'],
            ['name' => 'class_routines.create', 'label' => 'Create class routines'],
            ['name' => 'class_routines.update', 'label' => 'Update class routines'],
            ['name' => 'class_routines.delete', 'label' => 'Delete class routines'],

            ['name' => 'class_versions.view', 'label' => 'View class versions'],
            ['name' => 'class_versions.create', 'label' => 'Create class versions'],
            ['name' => 'class_versions.update', 'label' => 'Update class versions'],
            ['name' => 'class_versions.delete', 'label' => 'Delete class versions'],

            ['name' => 'versions.view', 'label' => 'View versions'],
            ['name' => 'versions.update', 'label' => 'Update versions'],

            ['name' => 'shifts.view', 'label' => 'View shifts'],
            ['name' => 'shifts.create', 'label' => 'Create shifts'],
            ['name' => 'shifts.update', 'label' => 'Update shifts'],
            ['name' => 'shifts.delete', 'label' => 'Delete shifts'],

            ['name' => 'sections.view', 'label' => 'View sections'],
            ['name' => 'sections.create', 'label' => 'Create sections'],
            ['name' => 'sections.update', 'label' => 'Update sections'],
            ['name' => 'sections.delete', 'label' => 'Delete sections'],
            ['name' => 'sections.override_capacity', 'label' => 'Override section capacity on transfer'],

            ['name' => 'subject_teacher_assignments.view', 'label' => 'View subject-teacher assignments'],
            ['name' => 'subject_teacher_assignments.create', 'label' => 'Create subject-teacher assignments'],
            ['name' => 'subject_teacher_assignments.update', 'label' => 'Update subject-teacher assignments'],
            ['name' => 'subject_teacher_assignments.delete', 'label' => 'Delete subject-teacher assignments'],

            ['name' => 'timetable_entries.view', 'label' => 'View timetable entries (including drafts)'],
            ['name' => 'timetable_entries.create', 'label' => 'Create timetable entries'],
            ['name' => 'timetable_entries.update', 'label' => 'Update timetable entries'],
            ['name' => 'timetable_entries.delete', 'label' => 'Delete timetable entries'],
            ['name' => 'timetable_entries.publish', 'label' => 'Publish or revise published timetable entries'],
            ['name' => 'timetable_entries.generate', 'label' => 'Automatically generate timetable entries for a shift'],

            // The exam-term calendar, grade/GPA scale, and per-class-subject full
            // marks are all national config (same tier as holidays.*) — .create/
            // .update/.delete are only ever granted to national_admin; every other
            // role gets .view only.
            ['name' => 'academic_exam_terms.view', 'label' => 'View academic exam terms'],
            ['name' => 'academic_exam_terms.create', 'label' => 'Create academic exam terms'],
            ['name' => 'academic_exam_terms.update', 'label' => 'Update academic exam terms'],
            ['name' => 'academic_exam_terms.delete', 'label' => 'Delete academic exam terms'],

            ['name' => 'academic_grade_configures.view', 'label' => 'View the academic grade scale'],
            ['name' => 'academic_grade_configures.create', 'label' => 'Create academic grade configuration'],
            ['name' => 'academic_grade_configures.update', 'label' => 'Update academic grade configuration'],
            ['name' => 'academic_grade_configures.delete', 'label' => 'Delete academic grade configuration'],

            ['name' => 'academic_subject_mark_configures.view', 'label' => 'View subject mark configuration'],
            ['name' => 'academic_subject_mark_configures.create', 'label' => 'Create subject mark configuration'],
            ['name' => 'academic_subject_mark_configures.update', 'label' => 'Update subject mark configuration'],
            ['name' => 'academic_subject_mark_configures.delete', 'label' => 'Delete subject mark configuration'],

            // Unlike attendances.*, these are deliberately NOT granted broadly in
            // RolePermissionSeeder — .view gates raw, possibly-unpublished marks
            // (a staff audit capability per the design spec), not a universally
            // viewable record like attendance. Only school_admin/teacher get
            // .view/.update; only school_admin gets .publish (national_admin gets
            // everything automatically).
            ['name' => 'academic_exam_marks.view', 'label' => 'View exam marks'],
            ['name' => 'academic_exam_marks.update', 'label' => 'Record or update exam marks'],
            ['name' => 'academic_exam_marks.publish', 'label' => 'Publish exam results to students'],

            // Granularity precedent: sections.override_capacity — a single,
            // standalone action permission, not split into .view/.create/.update/
            // .delete. No teacher exception anywhere: promotion is admin-only.
            ['name' => 'student_promotions.create', 'label' => 'Promote students to the next class'],
        ];

        foreach ($permissions as $permission) {
            Permission::updateOrCreate(['name' => $permission['name']], $permission);
        }
    }
}
