<?php

declare(strict_types=1);

/**
 * Public registration is intentionally disabled.
 * Otelex user accounts must be created by an authenticated administrator through POST /users/create.
 */

http_response_code(404);
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'status'  => 'failed',
    'message' => 'Route not found.',
]);
