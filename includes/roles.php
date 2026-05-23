<?php
// includes/roles.php
// Central role vocabulary and small permission helpers for Otelex.

declare(strict_types=1);

const ROLE_SUPER_ADMIN = 'super_admin';
const ROLE_ADMIN = 'admin';
const ROLE_SALES = 'sales';
const ROLE_ACCOUNTING = 'accounting';
const APP_ROLES = [ROLE_SUPER_ADMIN, ROLE_ADMIN, ROLE_SALES, ROLE_ACCOUNTING];

function hasRole(array $user, array $allowedRoles): bool
{
    return in_array((string) ($user['role'] ?? ''), $allowedRoles, true);
}

function requireRole(array $user, array $allowedRoles, string $message = 'You do not have permission to perform this action.'): void
{
    if (!hasRole($user, $allowedRoles)) {
        throw new Exception($message, 403);
    }
}

function isSuperAdmin(array $user): bool
{
    return ($user['role'] ?? '') === ROLE_SUPER_ADMIN;
}

function isOperationalAdmin(array $user): bool
{
    return hasRole($user, [ROLE_SUPER_ADMIN, ROLE_ADMIN]);
}
