<?php

/**
 * Maps each of the three portal-specific login endpoints
 * (POST /api/v1/auth/{portal}/login) to the role names allowed to
 * authenticate through it. A role not listed here for any portal
 * (trainer, office_staff, it_admin, auditor, parent_guardian) has no
 * portal login — see docs/superpowers/specs/2026-10-30-multi-portal-auth-design.md
 * "Portal-to-role mapping".
 */
return [
    'teacher' => ['teacher'],
    'student' => ['student'],
    'management' => ['national_admin', 'district_officer', 'school_admin'],
];
