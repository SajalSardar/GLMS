<?php

namespace Tests\Unit;

use App\Http\Resources\Api\V1\AcademicYearResource;
use App\Http\Resources\Api\V1\BookResource;
use App\Http\Resources\Api\V1\ChapterMaterialResource;
use App\Http\Resources\Api\V1\ChapterResource;
use App\Http\Resources\Api\V1\ChapterVideoResource;
use App\Http\Resources\Api\V1\ClassResource;
use App\Http\Resources\Api\V1\ClassroomResource;
use App\Http\Resources\Api\V1\ClassRoutineResource;
use App\Http\Resources\Api\V1\ClassVersionResource;
use App\Http\Resources\Api\V1\DistrictResource;
use App\Http\Resources\Api\V1\DivisionResource;
use App\Http\Resources\Api\V1\ExamResource;
use App\Http\Resources\Api\V1\GuardianResource;
use App\Http\Resources\Api\V1\QuestionResource;
use App\Http\Resources\Api\V1\RoleResource;
use App\Http\Resources\Api\V1\SchoolResource;
use App\Http\Resources\Api\V1\SectionResource;
use App\Http\Resources\Api\V1\ShiftResource;
use App\Http\Resources\Api\V1\StudentProfileResource;
use App\Http\Resources\Api\V1\SubjectResource;
use App\Http\Resources\Api\V1\SubjectTeacherAssignmentResource;
use App\Http\Resources\Api\V1\TeacherProfileResource;
use App\Http\Resources\Api\V1\TimetableEntryResource;
use App\Http\Resources\Api\V1\UpazilaResource;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\AcademicYear;
use App\Models\Book;
use App\Models\Chapter;
use App\Models\ChapterMaterial;
use App\Models\ChapterVideo;
use App\Models\Classroom;
use App\Models\ClassRoutine;
use App\Models\ClassVersion;
use App\Models\Concerns\Auditable;
use App\Models\District;
use App\Models\Division;
use App\Models\Exam;
use App\Models\Guardian;
use App\Models\Question;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Shift;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\SubjectTeacherAssignment;
use App\Models\TeacherProfile;
use App\Models\TimetableEntry;
use App\Models\Upazila;
use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Structural drift-guard test: catches a future audited model being added
 * without the Auditable trait, or an existing Resource class losing its
 * created_by/updated_by keys. Mirrors the house style of
 * ControllerAuthorizationConventionTest (reflection/iteration over a fixed
 * list of classes) rather than exercising any HTTP or auth behavior.
 */
class AuditableTraitCoverageTest extends TestCase
{
    /**
     * The 25 audited models, mapped to their corresponding Resource class.
     * SchoolClass is the one name mismatch: its resource is ClassResource,
     * not SchoolClassResource.
     *
     * @return array<string, array{0: class-string, 1: class-string}>
     */
    public static function auditedModels(): array
    {
        $pairs = [
            [Division::class, DivisionResource::class],
            [District::class, DistrictResource::class],
            [Upazila::class, UpazilaResource::class],
            [School::class, SchoolResource::class],
            [Subject::class, SubjectResource::class],
            [SchoolClass::class, ClassResource::class],
            [AcademicYear::class, AcademicYearResource::class],
            [Classroom::class, ClassroomResource::class],
            [ClassRoutine::class, ClassRoutineResource::class],
            [ClassVersion::class, ClassVersionResource::class],
            [Shift::class, ShiftResource::class],
            [Section::class, SectionResource::class],
            [SubjectTeacherAssignment::class, SubjectTeacherAssignmentResource::class],
            [TimetableEntry::class, TimetableEntryResource::class],
            [Book::class, BookResource::class],
            [Chapter::class, ChapterResource::class],
            [ChapterMaterial::class, ChapterMaterialResource::class],
            [ChapterVideo::class, ChapterVideoResource::class],
            [Question::class, QuestionResource::class],
            [Exam::class, ExamResource::class],
            [Role::class, RoleResource::class],
            [User::class, UserResource::class],
            [TeacherProfile::class, TeacherProfileResource::class],
            [StudentProfile::class, StudentProfileResource::class],
            [Guardian::class, GuardianResource::class],
        ];

        $named = [];

        foreach ($pairs as [$modelClass, $resourceClass]) {
            $named[$modelClass] = [$modelClass, $resourceClass];
        }

        return $named;
    }

    #[DataProvider('auditedModels')]
    public function test_audited_model_uses_auditable_trait(string $modelClass, string $resourceClass): void
    {
        $this->assertContains(
            Auditable::class,
            class_uses_recursive($modelClass),
            "{$modelClass} must use the Auditable trait."
        );
    }

    #[DataProvider('auditedModels')]
    public function test_audited_model_resource_exposes_created_by_and_updated_by(string $modelClass, string $resourceClass): void
    {
        // Unsaved instances are enough for every model except ChapterVideo,
        // whose resource unconditionally resolves an embed URL from
        // youtube_video_id — give it the one attribute it needs to render,
        // still without a DB round trip.
        $instance = $modelClass === ChapterVideo::class
            ? new ChapterVideo(['youtube_video_id' => 'dQw4w9WgXcQ'])
            : new $modelClass;

        $array = (new $resourceClass($instance))->toArray(request());

        $this->assertArrayHasKey(
            'created_by',
            $array,
            "{$resourceClass} must expose a created_by key."
        );
        $this->assertArrayHasKey(
            'updated_by',
            $array,
            "{$resourceClass} must expose an updated_by key."
        );
    }
}
