<?php

/**
 * Maps each of the four portal-specific login endpoints
 * (POST /api/v1/auth/{portal}/login) to the role names allowed to
 * authenticate through it. A role not listed here for any portal
 * (currently only office_staff) has no portal login — see
 * docs/superpowers/specs/2026-10-30-multi-portal-auth-design.md
 * "Portal-to-role mapping". trainer/it_admin/auditor were added to
 * 'management' later, once the Management Portal frontend grew nav items
 * and route guards for their (narrower, mostly read-only) permission
 * grants — see App.tsx's per-role nav composition.
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
    'management' => ['national_admin', 'district_officer', 'school_admin', 'trainer', 'it_admin', 'auditor'],
];
