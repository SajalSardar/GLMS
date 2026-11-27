<?php

use App\Http\Controllers\Api\V1\AcademicExamMarkController;
use App\Http\Controllers\Api\V1\AcademicExamPublicationController;
use App\Http\Controllers\Api\V1\AcademicExamTermController;
use App\Http\Controllers\Api\V1\AcademicGradeConfigureController;
use App\Http\Controllers\Api\V1\AcademicQuestionController;
use App\Http\Controllers\Api\V1\AcademicSubjectMarkConfigureController;
use App\Http\Controllers\Api\V1\AcademicYearController;
use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\AttendancePunchController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BookController;
use App\Http\Controllers\Api\V1\ChapterController;
use App\Http\Controllers\Api\V1\ChapterMaterialController;
use App\Http\Controllers\Api\V1\ChapterVideoController;
use App\Http\Controllers\Api\V1\ClassController;
use App\Http\Controllers\Api\V1\ClassroomController;
use App\Http\Controllers\Api\V1\ClassRoutineController;
use App\Http\Controllers\Api\V1\ClassSchoolController;
use App\Http\Controllers\Api\V1\ClassVersionController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DistrictController;
use App\Http\Controllers\Api\V1\DivisionController;
use App\Http\Controllers\Api\V1\ExamAttemptController;
use App\Http\Controllers\Api\V1\ExamController;
use App\Http\Controllers\Api\V1\GuardianController;
use App\Http\Controllers\Api\V1\GuardianUserController;
use App\Http\Controllers\Api\V1\GuestExamAttemptController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\HolidayController;
use App\Http\Controllers\Api\V1\KioskDeviceController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\PermissionController;
use App\Http\Controllers\Api\V1\PracticeExamController;
use App\Http\Controllers\Api\V1\QuestionController;
use App\Http\Controllers\Api\V1\QuestionPaperController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\SchoolController;
use App\Http\Controllers\Api\V1\SectionController;
use App\Http\Controllers\Api\V1\ShiftController;
use App\Http\Controllers\Api\V1\StudentLeaveApplicationController;
use App\Http\Controllers\Api\V1\StudentProfileController;
use App\Http\Controllers\Api\V1\StudentPromotionController;
use App\Http\Controllers\Api\V1\StudentUserController;
use App\Http\Controllers\Api\V1\SubjectController;
use App\Http\Controllers\Api\V1\SubjectTeacherAssignmentController;
use App\Http\Controllers\Api\V1\TeacherAttendanceController;
use App\Http\Controllers\Api\V1\TeacherLeaveApplicationController;
use App\Http\Controllers\Api\V1\TeacherProfileController;
use App\Http\Controllers\Api\V1\TeacherUserController;
use App\Http\Controllers\Api\V1\TimetableEntryController;
use App\Http\Controllers\Api\V1\UpazilaController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\VersionController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', HealthController::class);

    Route::prefix('auth')->group(function (): void {
        Route::post('/login', [AuthController::class, 'login']);
        Route::post('/teacher/login', [AuthController::class, 'teacherLogin']);
        Route::post('/student/login', [AuthController::class, 'studentLogin']);
        Route::post('/guardian/login', [AuthController::class, 'guardianLogin']);
        Route::post('/management/login', [AuthController::class, 'managementLogin']);

        Route::middleware('auth:sanctum')->group(function (): void {
            Route::post('/logout', [AuthController::class, 'logout']);
        });
    });

    Route::apiResource('divisions', DivisionController::class)->only(['index', 'show']);
    Route::apiResource('districts', DistrictController::class)->only(['index', 'show']);
    Route::apiResource('upazilas', UpazilaController::class)->only(['index', 'show']);
    Route::apiResource('schools', SchoolController::class)->only(['index', 'show']);
    Route::apiResource('subjects', SubjectController::class)->only(['index', 'show']);
    Route::apiResource('classes', ClassController::class)->only(['index', 'show']);
    Route::apiResource('class-schools', ClassSchoolController::class)->only(['index', 'show']);
    Route::apiResource('books', BookController::class)->only(['index', 'show']);
    Route::apiResource('chapters', ChapterController::class)->only(['index', 'show']);
    // Must be registered before the apiResource's wildcard {question} show
    // route below, or "export" would be matched as a question ID and
    // routed to show() instead of export(). Requires auth despite sitting
    // in this "public" section — see QuestionPolicy::export().
    Route::get('/questions/export', [QuestionController::class, 'export'])->middleware('auth:sanctum');
    Route::apiResource('questions', QuestionController::class)->only(['index', 'show']);
    // Must be registered before the apiResource's wildcard
    // {chapter_material} show route below, or "mine" would be matched as
    // a chapter_material ID and routed to show() instead of mine().
    // Requires auth despite sitting in this "public" section — self-
    // scoped to the logged-in student.
    Route::get('/chapter-materials/mine', [ChapterMaterialController::class, 'mine'])->middleware('auth:sanctum');
    // Same wildcard-shadowing gotcha as /chapter-materials/mine above —
    // must be registered before the apiResource's {chapter_material}
    // show route below, or these would be matched as chapter_material
    // IDs and routed to show() instead.
    Route::get('/chapter-materials/my-assignments', [ChapterMaterialController::class, 'myAssignments'])->middleware('auth:sanctum');
    Route::get('/chapter-materials/my-uploads', [ChapterMaterialController::class, 'myUploads'])->middleware('auth:sanctum');
    Route::apiResource('chapter-materials', ChapterMaterialController::class)->only(['index', 'show']);
    Route::apiResource('chapter-videos', ChapterVideoController::class)->only(['index', 'show']);
    Route::apiResource('academic-years', AcademicYearController::class)->only(['index', 'show']);
    Route::apiResource('academic-exam-terms', AcademicExamTermController::class)->only(['index', 'show']);
    Route::apiResource('academic-grade-configures', AcademicGradeConfigureController::class)->only(['index', 'show']);
    Route::apiResource('academic-subject-mark-configures', AcademicSubjectMarkConfigureController::class)->only(['index', 'show']);
    Route::apiResource('holidays', HolidayController::class)->only(['index', 'show']);
    Route::apiResource('classrooms', ClassroomController::class)->only(['index', 'show']);
    // Must be registered before the apiResource's wildcard {class_routine}
    // show route below, or "mine" would be matched as a class_routine ID
    // and routed to show() instead of mine(). Requires auth despite
    // sitting in this "public" section — self-scoped to the logged-in
    // student, same pattern as chapter-materials/mine above.
    Route::get('/class-routines/mine', [ClassRoutineController::class, 'mine'])->middleware('auth:sanctum');
    Route::apiResource('class-routines', ClassRoutineController::class)->only(['index', 'show']);
    Route::apiResource('class-versions', ClassVersionController::class)->only(['index', 'show']);
    Route::apiResource('versions', VersionController::class)->only(['index', 'show']);
    Route::apiResource('shifts', ShiftController::class)->only(['index', 'show']);
    Route::apiResource('sections', SectionController::class)->only(['index', 'show']);
    Route::apiResource('subject-teacher-assignments', SubjectTeacherAssignmentController::class)->only(['index', 'show']);
    // Public, but internally limited to published entries unless the
    // caller is authenticated with timetable_entries.view — see
    // TimetableEntryController::index()/show().
    Route::apiResource('timetable-entries', TimetableEntryController::class)->only(['index', 'show']);
    Route::apiResource('exams', ExamController::class)->only(['index', 'show']);
    // Throttled inline rather than on the whole `api` group (which has
    // no rate limiter configured anywhere else): these two are public,
    // unauthenticated, and randomDraw()/submit() hand back full question
    // text and (on submit) the correct option IDs — without a limiter,
    // either could be hammered to scrape a practice exam's entire
    // question bank and answer key.
    Route::post('/exams/{exam}/random-draw', [GuestExamAttemptController::class, 'randomDraw'])->middleware('throttle:30,1');
    Route::post('/exams/{exam}/guest-submissions', [GuestExamAttemptController::class, 'submit'])->middleware('throttle:30,1');
    // Same reasoning as above — public and a POST writes a row per call.
    Route::get('/practice-exams/config', [PracticeExamController::class, 'config'])->middleware('throttle:30,1');
    Route::post('/practice-exams', [PracticeExamController::class, 'store'])->middleware('throttle:30,1');

    // Device-facing, not a portal user — authenticated by the kiosk.auth
    // middleware's own token check, not auth:sanctum. Throttled the same
    // way the other public write endpoint above is.
    Route::post('/attendance-punches', [AttendancePunchController::class, 'store'])
        ->middleware(['kiosk.auth', 'throttle:120,1']);

    Route::middleware('auth:sanctum')->group(function (): void {
        // Self-service "my account" — the one route every login-table
        // model (User/TeacherUser/StudentUser/GuardianUser) shares, since
        // each already resolves correctly via $request->user() regardless
        // of which table backed the token. See MeController's own comment.
        Route::get('/me', [MeController::class, 'show']);
        Route::patch('/me', [MeController::class, 'update']);
        Route::patch('/me/password', [MeController::class, 'updatePassword']);

        Route::apiResource('divisions', DivisionController::class)->only(['store', 'update', 'destroy']);
        Route::apiResource('districts', DistrictController::class)->only(['store', 'update', 'destroy']);
        Route::apiResource('upazilas', UpazilaController::class)->only(['store', 'update', 'destroy']);
        Route::apiResource('schools', SchoolController::class)->only(['store', 'update', 'destroy']);
        Route::apiResource('subjects', SubjectController::class)->only(['store', 'update', 'destroy']);
        Route::apiResource('books', BookController::class)->only(['store', 'update', 'destroy']);
        Route::apiResource('chapters', ChapterController::class)->only(['store', 'update', 'destroy']);
        Route::patch('/questions/reorder', [QuestionController::class, 'reorder']);
        Route::post('/questions/import', [QuestionController::class, 'import']);
        Route::apiResource('questions', QuestionController::class)->only(['store', 'update', 'destroy']);

        Route::apiResource('exams', ExamController::class)->only(['store', 'update', 'destroy']);
        Route::patch('/exams/{exam}/questions', [ExamController::class, 'setQuestions']);
        Route::patch('/exams/{exam}/random-chapters', [ExamController::class, 'setRandomChapters']);
        Route::post('/exams/{exam}/randomize', [ExamController::class, 'randomize']);

        Route::post('/exams/{exam}/attempts', [ExamAttemptController::class, 'start']);
        Route::get('/exams/{exam}/attempts', [ExamAttemptController::class, 'index']);
        Route::get('/my-exam-attempts', [ExamAttemptController::class, 'mine']);
        Route::get('/exam-attempts/{exam_attempt}', [ExamAttemptController::class, 'show']);
        Route::put('/exam-attempts/{exam_attempt}/answers/{question}', [ExamAttemptController::class, 'saveAnswer']);
        Route::post('/exam-attempts/{exam_attempt}/submit', [ExamAttemptController::class, 'submit']);

        // Mints a signed URL for the PDF's actual bytes, so it is gated
        // like the other book-PDF actions rather than sitting with the
        // public metadata routes above — the signature it returns is a
        // working download credential for a private-disk, licensed file,
        // and the streaming routes themselves only validate that
        // signature. Same reasoning as chapter-materials/download-link
        // below, which has always lived in this group.
        Route::post('/books/{book}/pdf/download-link', [BookController::class, 'pdfDownloadLink']);
        Route::post('/books/{book}/pdf', [BookController::class, 'uploadPdf']);
        Route::patch('/books/{book}/pdf/status', [BookController::class, 'updatePdfStatus']);
        Route::delete('/books/{book}/pdf', [BookController::class, 'deletePdf']);

        Route::apiResource('chapter-materials', ChapterMaterialController::class)->only(['store', 'update', 'destroy']);
        Route::post('/chapter-materials/{chapter_material}/download-link', [ChapterMaterialController::class, 'downloadLink']);

        Route::patch('/chapter-videos/reorder', [ChapterVideoController::class, 'reorder']);
        Route::apiResource('chapter-videos', ChapterVideoController::class)->only(['store', 'update', 'destroy']);

        Route::apiResource('classes', ClassController::class)->only(['store', 'update', 'destroy']);
        Route::apiResource('class-schools', ClassSchoolController::class)->only(['store', 'destroy']);
        Route::put('/class-schools/{class_school}/subjects', [ClassSchoolController::class, 'updateSubjects']);

        Route::apiResource('academic-years', AcademicYearController::class)->only(['store', 'update', 'destroy']);
        Route::patch('/academic-years/{academic_year}/set-current', [AcademicYearController::class, 'setCurrent']);

        Route::apiResource('holidays', HolidayController::class)->only(['store', 'update', 'destroy']);
        Route::apiResource('academic-exam-terms', AcademicExamTermController::class)->only(['store', 'update', 'destroy']);
        Route::apiResource('academic-grade-configures', AcademicGradeConfigureController::class)->only(['store', 'update', 'destroy']);
        Route::apiResource('academic-subject-mark-configures', AcademicSubjectMarkConfigureController::class)->only(['store', 'update', 'destroy']);

        Route::apiResource('classrooms', ClassroomController::class)->only(['store', 'update', 'destroy']);
        Route::apiResource('class-routines', ClassRoutineController::class)->only(['store', 'update', 'destroy']);

        Route::apiResource('class-versions', ClassVersionController::class)->only(['store', 'update', 'destroy']);
        Route::put('/class-versions/{class_version}/subjects', [ClassVersionController::class, 'updateSubjects']);

        Route::apiResource('versions', VersionController::class)->only(['update']);

        Route::apiResource('shifts', ShiftController::class)->only(['store', 'update', 'destroy']);
        Route::apiResource('sections', SectionController::class)->only(['store', 'update', 'destroy']);
        Route::apiResource('subject-teacher-assignments', SubjectTeacherAssignmentController::class)->only(['store', 'update', 'destroy']);

        Route::apiResource('timetable-entries', TimetableEntryController::class)->only(['store', 'update', 'destroy']);
        Route::patch('/timetable-entries/{timetable_entry}/publish', [TimetableEntryController::class, 'publish']);
        Route::patch('/timetable-entries/{timetable_entry}/unpublish', [TimetableEntryController::class, 'unpublish']);
        Route::post('/shifts/{shift}/timetable-entries/generate', [TimetableEntryController::class, 'generate']);

        Route::apiResource('roles', RoleController::class);
        Route::put('/roles/{role}/permissions', [RoleController::class, 'updatePermissions']);
        Route::get('/permissions', [PermissionController::class, 'index']);

        Route::apiResource('users', UserController::class);
        Route::patch('/users/{user}/role', [UserController::class, 'assignRole']);

        Route::apiResource('teacher-users', TeacherUserController::class);
        Route::apiResource('student-users', StudentUserController::class);
        Route::apiResource('guardian-users', GuardianUserController::class);

        Route::apiResource('teacher-profiles', TeacherProfileController::class);

        Route::apiResource('student-profiles', StudentProfileController::class);
        Route::post('/student-profiles/{student_profile}/guardians', [StudentProfileController::class, 'attachGuardian']);
        Route::delete('/student-profiles/{student_profile}/guardians/{guardian}', [StudentProfileController::class, 'detachGuardian']);
        Route::post('/student-profiles/{student_profile}/transfer-section', [StudentProfileController::class, 'transferSection']);
        Route::get('/student-profiles/{student_profile}/dashboard', [StudentProfileController::class, 'dashboard']);

        Route::get('/attendances/my-sections', [AttendanceController::class, 'mySections']);
        Route::get('/attendances/roster', [AttendanceController::class, 'roster']);
        Route::post('/attendances/record', [AttendanceController::class, 'record']);
        Route::get('/attendances/report', [AttendanceController::class, 'report']);
        Route::get('/attendances/mine', [AttendanceController::class, 'mine']);

        Route::get('/academic-exam-marks/my-assignments', [AcademicExamMarkController::class, 'myAssignments']);
        Route::get('/academic-exam-marks/roster', [AcademicExamMarkController::class, 'roster']);
        Route::post('/academic-exam-marks/record', [AcademicExamMarkController::class, 'record']);
        Route::get('/academic-exam-marks/marksheet', [AcademicExamMarkController::class, 'marksheet']);
        Route::get('/academic-exam-marks/section-marksheet', [AcademicExamMarkController::class, 'sectionMarksheet']);
        Route::get('/academic-exam-marks/section-students', [AcademicExamMarkController::class, 'sectionStudents']);
        Route::get('/academic-exam-marks/mine/terms', [AcademicExamMarkController::class, 'mineTerms']);
        Route::get('/academic-exam-marks/mine', [AcademicExamMarkController::class, 'mine']);

        Route::get('/academic-exam-publications/status', [AcademicExamPublicationController::class, 'status']);
        Route::post('/academic-exam-publications', [AcademicExamPublicationController::class, 'store']);
        Route::delete('/academic-exam-publications', [AcademicExamPublicationController::class, 'destroy']);

        Route::get('/student-promotions/preview', [StudentPromotionController::class, 'preview']);
        Route::post('/student-promotions', [StudentPromotionController::class, 'store']);

        Route::post('/student-leave-applications', [StudentLeaveApplicationController::class, 'store']);
        // Must be registered before the {student_leave_application}
        // wildcard show route below, or "mine" would be matched as an
        // application ID — same gotcha as chapter-materials/mine.
        Route::get('/student-leave-applications/mine', [StudentLeaveApplicationController::class, 'mine']);
        Route::get('/student-leave-applications', [StudentLeaveApplicationController::class, 'index']);
        Route::get('/student-leave-applications/{student_leave_application}', [StudentLeaveApplicationController::class, 'show']);
        Route::patch('/student-leave-applications/{student_leave_application}/review', [StudentLeaveApplicationController::class, 'review']);

        Route::post('/teacher-leave-applications', [TeacherLeaveApplicationController::class, 'store']);
        // "mine" and "colleagues" must be registered before the
        // {teacher_leave_application} wildcard show route below, same
        // gotcha as student-leave-applications/mine.
        Route::get('/teacher-leave-applications/mine', [TeacherLeaveApplicationController::class, 'mine']);
        Route::get('/teacher-leave-applications/colleagues', [TeacherLeaveApplicationController::class, 'colleagues']);
        Route::get('/teacher-leave-applications', [TeacherLeaveApplicationController::class, 'index']);
        Route::get('/teacher-leave-applications/{teacher_leave_application}', [TeacherLeaveApplicationController::class, 'show']);
        Route::patch('/teacher-leave-applications/{teacher_leave_application}/review', [TeacherLeaveApplicationController::class, 'review']);

        Route::apiResource('kiosk-devices', KioskDeviceController::class)->only(['index', 'store', 'update', 'destroy']);

        Route::get('/teacher-attendances/report', [TeacherAttendanceController::class, 'report']);
        Route::get('/teacher-attendances/mine', [TeacherAttendanceController::class, 'mine']);
        Route::patch('/teacher-attendances/correct', [TeacherAttendanceController::class, 'correct']);

        Route::apiResource('academic-questions', AcademicQuestionController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::apiResource('question-papers', QuestionPaperController::class)->only(['index', 'show', 'store', 'update', 'destroy']);

        Route::get('/guardians/me/children', [GuardianController::class, 'myChildren']);
        Route::apiResource('guardians', GuardianController::class);

        Route::get('/dashboard/summary', [DashboardController::class, 'summary']);
    });

    Route::middleware('signed')->group(function (): void {
        Route::get('/books/{book}/pdf/download', [BookController::class, 'downloadPdf'])->name('books.pdf.download');
        Route::get('/books/{book}/pdf/preview', [BookController::class, 'previewPdf'])->name('books.pdf.preview');
        Route::get('/chapter-materials/{chapter_material}/download', [ChapterMaterialController::class, 'download'])->name('chapter-materials.download');
        Route::get('/chapter-materials/{chapter_material}/preview', [ChapterMaterialController::class, 'preview'])->name('chapter-materials.preview');
    });
});
