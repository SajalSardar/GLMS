<?php

namespace Tests\Unit;

use Tests\TestCase;

class PortalsConfigTest extends TestCase
{
    public function test_teacher_portal_allows_only_the_teacher_role(): void
    {
        $this->assertSame(['teacher'], config('portals.teacher'));
    }

    public function test_student_portal_allows_only_the_student_role(): void
    {
        $this->assertSame(['student'], config('portals.student'));
    }

    public function test_management_portal_allows_the_three_administrative_roles(): void
    {
        $this->assertSame(['national_admin', 'district_officer', 'school_admin'], config('portals.management'));
    }
}
