<?php

/**
 * Maps each of the four portal-specific login endpoints
 * (POST /api/v1/auth/{portal}/login) to the role names allowed to
 * authenticate through it. A role not listed here for any portal
 * (trainer, office_staff, it_admin, auditor) has no portal login —
 * see docs/superpowers/specs/2026-10-30-multi-portal-auth-design.md
 * "Portal-to-role mapping".
 *
 * There is intentionally no entry for the portal-agnostic
 * POST /api/v1/auth/login: it is not a portal and enforces no allowlist
 * by design — see AuthController::login()'s docblock for why, before
 * adding one here.
 */
return [
    'teacher' => ['teacher'],
    'student' => ['student'],
    'guardian' => ['parent_guardian'],
    'management' => ['national_admin', 'district_officer', 'school_admin'],
];
