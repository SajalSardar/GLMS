<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\V1\AcademicYearController;
use App\Http\Controllers\Api\V1\BookController;
use App\Http\Controllers\Api\V1\ChapterController;
use App\Http\Controllers\Api\V1\ChapterMaterialController;
use App\Http\Controllers\Api\V1\ChapterVideoController;
use App\Http\Controllers\Api\V1\ClassController;
use App\Http\Controllers\Api\V1\ClassroomController;
use App\Http\Controllers\Api\V1\ClassRoutineController;
use App\Http\Controllers\Api\V1\ClassVersionController;
use App\Http\Controllers\Api\V1\DistrictController;
use App\Http\Controllers\Api\V1\DivisionController;
use App\Http\Controllers\Api\V1\ExamAttemptController;
use App\Http\Controllers\Api\V1\ExamController;
use App\Http\Controllers\Api\V1\GuardianController;
use App\Http\Controllers\Api\V1\GuardianUserController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\SchoolController;
use App\Http\Controllers\Api\V1\SectionController;
use App\Http\Controllers\Api\V1\ShiftController;
use App\Http\Controllers\Api\V1\StudentProfileController;
use App\Http\Controllers\Api\V1\StudentUserController;
use App\Http\Controllers\Api\V1\SubjectController;
use App\Http\Controllers\Api\V1\SubjectTeacherAssignmentController;
use App\Http\Controllers\Api\V1\TeacherProfileController;
use App\Http\Controllers\Api\V1\TeacherUserController;
use App\Http\Controllers\Api\V1\TimetableEntryController;
use App\Http\Controllers\Api\V1\UpazilaController;
use App\Http\Controllers\Api\V1\UserController;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Structural regression test for this plan's actual deliverable: every
 * mutating action on the 23 Api\V1 controllers must call
 * `$this->authorize(...)` as the literal first statement of its method
 * body. The HTTP-level feature tests added alongside each controller's
 * authorize() call cannot detect a revert of that call, because every
 * resource's FormRequest independently enforces the same Policy check
 * before the controller method ever runs — this test is the one that
 * actually fails if an authorize() call is removed.
 */
class ControllerAuthorizationConventionTest extends TestCase
{
    /**
     * Methods that call `authorize()` indirectly through a shared helper
     * rather than as their own literal first statement, by design:
     * `TimetableEntryController::update()`/`::destroy()` both delegate to
     * `assertEditable()`, which calls `authorize('update', $entry)` as
     * ITS first statement (see the class's hard-constraint-#10 docblock).
     *
     * @return array<int, array{0: class-string, 1: string}>
     */
    private static function whitelistedExceptions(): array
    {
        return [
            [TimetableEntryController::class, 'update'],
            [TimetableEntryController::class, 'destroy'],
        ];
    }

    /**
     * @return array<string, array{0: class-string, 1: string}>
     */
    public static function mutatingActions(): array
    {
        $actions = [
            [AcademicYearController::class, 'store'],
            [AcademicYearController::class, 'update'],
            [AcademicYearController::class, 'destroy'],
            [AcademicYearController::class, 'setCurrent'],

            [BookController::class, 'store'],
            [BookController::class, 'update'],
            [BookController::class, 'destroy'],
            [BookController::class, 'uploadPdf'],
            [BookController::class, 'updatePdfStatus'],
            [BookController::class, 'deletePdf'],

            [ChapterMaterialController::class, 'store'],
            [ChapterMaterialController::class, 'update'],
            [ChapterMaterialController::class, 'destroy'],

            [ChapterVideoController::class, 'store'],
            [ChapterVideoController::class, 'update'],
            [ChapterVideoController::class, 'destroy'],
            [ChapterVideoController::class, 'reorder'],

            [ChapterController::class, 'store'],
            [ChapterController::class, 'update'],
            [ChapterController::class, 'destroy'],

            [ClassController::class, 'store'],
            [ClassController::class, 'update'],
            [ClassController::class, 'destroy'],
            [ClassController::class, 'updateSubjects'],

            [ClassRoutineController::class, 'store'],
            [ClassRoutineController::class, 'update'],
            [ClassRoutineController::class, 'destroy'],

            [ClassVersionController::class, 'store'],
            [ClassVersionController::class, 'update'],
            [ClassVersionController::class, 'destroy'],
            [ClassVersionController::class, 'updateSubjects'],

            [ClassroomController::class, 'store'],
            [ClassroomController::class, 'update'],
            [ClassroomController::class, 'destroy'],

            [DistrictController::class, 'store'],
            [DistrictController::class, 'update'],
            [DistrictController::class, 'destroy'],

            [DivisionController::class, 'store'],
            [DivisionController::class, 'update'],
            [DivisionController::class, 'destroy'],

            [ExamController::class, 'store'],
            [ExamController::class, 'update'],
            [ExamController::class, 'destroy'],
            [ExamController::class, 'setQuestions'],
            [ExamController::class, 'randomize'],

            [ExamAttemptController::class, 'start'],

            [GuardianController::class, 'store'],
            [GuardianController::class, 'update'],
            [GuardianController::class, 'destroy'],

            [GuardianUserController::class, 'store'],
            [GuardianUserController::class, 'update'],
            [GuardianUserController::class, 'destroy'],

            [RoleController::class, 'store'],
            [RoleController::class, 'update'],
            [RoleController::class, 'destroy'],
            [RoleController::class, 'updatePermissions'],

            [SchoolController::class, 'store'],
            [SchoolController::class, 'update'],
            [SchoolController::class, 'destroy'],

            [SectionController::class, 'store'],
            [SectionController::class, 'update'],
            [SectionController::class, 'destroy'],

            [ShiftController::class, 'store'],
            [ShiftController::class, 'update'],
            [ShiftController::class, 'destroy'],

            [StudentProfileController::class, 'store'],
            [StudentProfileController::class, 'update'],
            [StudentProfileController::class, 'destroy'],
            [StudentProfileController::class, 'attachGuardian'],
            [StudentProfileController::class, 'detachGuardian'],
            [StudentProfileController::class, 'transferSection'],

            [StudentUserController::class, 'store'],
            [StudentUserController::class, 'update'],
            [StudentUserController::class, 'destroy'],

            [SubjectController::class, 'store'],
            [SubjectController::class, 'update'],
            [SubjectController::class, 'destroy'],

            [SubjectTeacherAssignmentController::class, 'store'],
            [SubjectTeacherAssignmentController::class, 'update'],
            [SubjectTeacherAssignmentController::class, 'destroy'],

            [TeacherProfileController::class, 'store'],
            [TeacherProfileController::class, 'update'],
            [TeacherProfileController::class, 'destroy'],

            [TeacherUserController::class, 'store'],
            [TeacherUserController::class, 'update'],
            [TeacherUserController::class, 'destroy'],

            [TimetableEntryController::class, 'store'],
            [TimetableEntryController::class, 'update'],
            [TimetableEntryController::class, 'destroy'],
            [TimetableEntryController::class, 'publish'],
            [TimetableEntryController::class, 'unpublish'],
            [TimetableEntryController::class, 'generate'],

            [UpazilaController::class, 'store'],
            [UpazilaController::class, 'update'],
            [UpazilaController::class, 'destroy'],

            [UserController::class, 'store'],
            [UserController::class, 'update'],
            [UserController::class, 'destroy'],
            [UserController::class, 'assignRole'],
        ];

        $named = [];

        foreach ($actions as [$class, $method]) {
            $named["{$class}::{$method}"] = [$class, $method];
        }

        return $named;
    }

    #[DataProvider('mutatingActions')]
    public function test_mutating_action_authorizes_as_its_first_statement(string $controllerClass, string $method): void
    {
        if (in_array([$controllerClass, $method], self::whitelistedExceptions(), true)) {
            $this->assertTrue(
                true,
                "{$controllerClass}::{$method} is a documented exception — it authorizes indirectly via a shared helper."
            );

            return;
        }

        $reflection = new ReflectionMethod($controllerClass, $method);
        $lines = file($reflection->getFileName());
        $body = implode('', array_slice($lines, $reflection->getStartLine(), $reflection->getEndLine() - $reflection->getStartLine() - 1));

        $statements = array_values(array_filter(
            array_map('trim', explode("\n", $body)),
            fn (string $line): bool => $line !== '' && $line !== '{' && $line !== '}'
        ));

        $this->assertNotEmpty($statements, "{$controllerClass}::{$method} has no statements to inspect.");
        $this->assertStringStartsWith(
            '$this->authorize(',
            $statements[0],
            "{$controllerClass}::{$method} must call \$this->authorize() as its first statement, found: \"{$statements[0]}\""
        );
    }
}
