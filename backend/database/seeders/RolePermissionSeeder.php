<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Maps each role to the permissions matching its description in
     * `inspiration/AI_Teacher_Training_Proposal_v2.md` §3, limited to
     * resources the API currently exposes. Per §3's permission-model note,
     * the actual school/district *scope* for each role is enforced later
     * via Policies reading the user's school_id/district_id — this table
     * only defines which actions a role may perform, not where.
     */
    public function run(): void
    {
        $allPermissions = Permission::pluck('name')->all();

        $rolePermissions = [
            'national_admin' => $allPermissions,

            'district_officer' => [
                'divisions.view', 'districts.view', 'upazilas.view', 'schools.view',
                'classes.view', 'subjects.view', 'chapter_videos.view', 'chapter_materials.view', 'books.view', 'chapters.view', 'questions.view', 'exams.view', 'exam_attempts.view', 'student_progress.view', 'academic_years.view',
                'classrooms.view', 'class_routines.view', 'holidays.view',
                'academic_exam_terms.view', 'academic_grade_configures.view', 'academic_subject_mark_configures.view',
                'versions.view', 'class_versions.view', 'shifts.view', 'sections.view',
                'subject_teacher_assignments.view', 'timetable_entries.view',
                'teacher_profiles.view', 'student_profiles.view', 'student_leave_applications.view',
                'attendances.view',
            ],

            'school_admin' => [
                'divisions.view', 'districts.view', 'upazilas.view',
                'schools.view', 'schools.update',
                'classes.view',
                'class_schools.view', 'class_schools.create', 'class_schools.delete', 'class_schools.updateSubjects',
                'subjects.view', 'chapter_videos.view', 'chapter_materials.view', 'books.view', 'chapters.view', 'questions.view',
                'exams.view', 'exams.create', 'exams.update', 'exam_attempts.view', 'student_progress.view',
                'academic_years.view', 'holidays.view',
                'academic_exam_terms.view', 'academic_grade_configures.view', 'academic_subject_mark_configures.view',
                'classrooms.view', 'classrooms.create', 'classrooms.update', 'classrooms.delete',
                'class_routines.view', 'class_routines.create', 'class_routines.update', 'class_routines.delete',
                'versions.view', 'class_versions.view', 'class_versions.create', 'class_versions.update', 'class_versions.delete',
                'shifts.view', 'shifts.create', 'shifts.update', 'shifts.delete',
                'sections.view', 'sections.create', 'sections.update', 'sections.delete', 'sections.override_capacity',
                'subject_teacher_assignments.view', 'subject_teacher_assignments.create',
                'subject_teacher_assignments.update', 'subject_teacher_assignments.delete',
                'timetable_entries.view', 'timetable_entries.create', 'timetable_entries.update',
                'timetable_entries.delete', 'timetable_entries.publish', 'timetable_entries.generate',
                'users.view', 'users.create', 'users.update',
                'teacher_profiles.view', 'teacher_profiles.create', 'teacher_profiles.update', 'teacher_profiles.delete',
                'student_profiles.view', 'student_profiles.create', 'student_profiles.update', 'student_profiles.delete',
                'student_leave_applications.view', 'student_leave_applications.update',
                'attendances.view', 'attendances.update',
                'academic_exam_marks.view', 'academic_exam_marks.update', 'academic_exam_marks.publish',
                'student_promotions.create',
                'guardians.view', 'guardians.create', 'guardians.update', 'guardians.delete',
            ],

            'teacher' => [
                'divisions.view', 'districts.view', 'upazilas.view', 'schools.view',
                'classes.view', 'subjects.view', 'chapter_videos.view', 'chapter_materials.view', 'chapter_materials.create', 'chapter_materials.update', 'chapter_materials.delete', 'books.view', 'chapters.view', 'academic_years.view',
                'classrooms.view', 'class_routines.view', 'holidays.view',
                'academic_exam_terms.view', 'academic_grade_configures.view', 'academic_subject_mark_configures.view',
                'versions.view', 'class_versions.view', 'shifts.view', 'sections.view', 'subject_teacher_assignments.view',
                'questions.view',
                'exams.view', 'exams.create', 'exams.update', 'exam_attempts.view', 'student_progress.view',
                'teacher_profiles.view', 'student_profiles.view',
                // Reviewing a leave application is additionally gated to
                // the class teacher of that student's own section (see
                // StudentLeaveApplicationPolicy::review()) — this
                // permission alone only lets a teacher actor reach the
                // endpoint, not review anyone's application.
                'student_leave_applications.view', 'student_leave_applications.update',
                // Same shape as the leave-application pair above: actually
                // recording attendance is additionally gated to the
                // section's own class teacher (AttendancePolicy::
                // recordSection()) — this pair alone only lets a teacher
                // actor reach the endpoints, not record for every section
                // in the school.
                'attendances.view', 'attendances.update',
                'academic_exam_marks.view', 'academic_exam_marks.update',
            ],

            'student' => [
                'schools.view', 'classes.view', 'subjects.view', 'chapter_videos.view', 'chapter_materials.view', 'books.view', 'chapters.view', 'questions.view', 'academic_years.view',
                'classrooms.view', 'class_routines.view', 'holidays.view',
                'academic_exam_terms.view', 'academic_grade_configures.view', 'academic_subject_mark_configures.view',
                'versions.view', 'class_versions.view', 'shifts.view', 'sections.view',
                'student_profiles.view', 'exam_attempts.create',
            ],

            'trainer' => [
                'divisions.view', 'districts.view', 'upazilas.view', 'schools.view',
                'classes.view', 'academic_years.view', 'classrooms.view', 'class_routines.view', 'holidays.view',
                'academic_exam_terms.view', 'academic_grade_configures.view', 'academic_subject_mark_configures.view',
                'versions.view', 'class_versions.view', 'shifts.view', 'sections.view', 'subject_teacher_assignments.view',
                'subjects.view', 'subjects.create', 'subjects.update',
                'chapter_videos.view',
                'chapter_materials.view',
                'books.view',
                'chapters.view',
                'questions.view',
                'exams.view', 'exams.create', 'exams.update', 'exam_attempts.view', 'student_progress.view',
                'teacher_profiles.view',
            ],

            'office_staff' => [
                'divisions.view', 'districts.view', 'upazilas.view', 'schools.view',
                'classes.view', 'subjects.view', 'chapter_videos.view', 'chapter_materials.view', 'books.view', 'chapters.view', 'questions.view', 'academic_years.view',
                'classrooms.view', 'class_routines.view', 'holidays.view',
                'academic_exam_terms.view', 'academic_grade_configures.view', 'academic_subject_mark_configures.view',
                'versions.view', 'class_versions.view', 'shifts.view', 'sections.view', 'subject_teacher_assignments.view',
                'teacher_profiles.view', 'student_profiles.view', 'student_leave_applications.view',
                'attendances.view',
                'guardians.view', 'guardians.create', 'guardians.update',
            ],

            'parent_guardian' => [
                'schools.view', 'classes.view', 'subjects.view', 'chapter_videos.view', 'chapter_materials.view', 'books.view', 'chapters.view', 'questions.view', 'academic_years.view',
                'classrooms.view', 'class_routines.view', 'holidays.view',
                'academic_exam_terms.view', 'academic_grade_configures.view', 'academic_subject_mark_configures.view',
                'versions.view', 'class_versions.view', 'shifts.view', 'sections.view',
                'student_profiles.view', 'guardians.view', 'attendances.view',
            ],

            'it_admin' => [
                'divisions.view', 'districts.view', 'upazilas.view', 'schools.view',
                'classes.view', 'subjects.view', 'chapter_videos.view', 'chapter_materials.view', 'books.view', 'chapters.view', 'questions.view', 'academic_years.view',
                'classrooms.view', 'class_routines.view', 'holidays.view',
                'academic_exam_terms.view', 'academic_grade_configures.view', 'academic_subject_mark_configures.view',
                'versions.view', 'class_versions.view', 'shifts.view', 'sections.view',
            ],

            'auditor' => [
                'divisions.view', 'districts.view', 'upazilas.view', 'schools.view',
                'classes.view', 'subjects.view', 'chapter_videos.view', 'chapter_materials.view', 'books.view', 'chapters.view', 'questions.view', 'academic_years.view',
                'classrooms.view', 'class_routines.view', 'holidays.view',
                'academic_exam_terms.view', 'academic_grade_configures.view', 'academic_subject_mark_configures.view',
                'versions.view', 'class_versions.view', 'shifts.view', 'sections.view',
                'subject_teacher_assignments.view', 'timetable_entries.view',
                'users.view', 'roles.view', 'permissions.view',
                'teacher_profiles.view', 'student_profiles.view', 'student_leave_applications.view', 'guardians.view',
                'attendances.view',
            ],
        ];

        foreach ($rolePermissions as $roleName => $permissionNames) {
            $role = Role::where('name', $roleName)->firstOrFail();
            $permissionIds = Permission::whereIn('name', $permissionNames)->pluck('id');

            $role->permissions()->sync($permissionIds);
        }
    }
}
