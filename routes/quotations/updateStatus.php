<?php
// routes/quotations/updateStatus.php
// PUT /quotations/update-status - legacy-safe quotation status update route.
// The main app should continue to use the dedicated send/accept/reject/reopen/expire/conversion routes.

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';

header('Content-Type: application/json');

date_default_timezone_set('Africa/Lagos');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        throw new Exception('Route not found', 400);
    }

    $userData = authenticateUser();
    $loggedInUserId = (int) ($userData['id'] ?? 0);
    $loggedInUserRole = (string) ($userData['role'] ?? '');

    if (!in_array($loggedInUserRole, ['super_admin', 'admin'], true)) {
        throw new Exception('Unauthorized: Only administrators can update quotation status directly.', 403);
    }

    $payload = json_decode(file_get_contents('php://input'), true) ?: [];
    $quotationId = isset($payload['quotation_id']) ? (int) $payload['quotation_id'] : (int) ($payload['id'] ?? 0);
    $status = strtolower(trim((string) ($payload['status'] ?? '')));
    $reason = trim((string) ($payload['reason'] ?? ''));

    $validStatuses = ['draft', 'sent', 'accepted', 'rejected', 'expired', 'converted'];
    if ($quotationId <= 0) {
        throw new Exception('Quotation ID is required.', 422);
    }
    if (!in_array($status, $validStatuses, true)) {
        throw new Exception('Invalid quotation status supplied.', 422);
    }

    $stmt = $conn->prepare(
        'SELECT q.id, q.quotation_number, q.status, q.client_id, c.company_name
         FROM quotations q
         INNER JOIN clients c ON c.id = q.client_id
         WHERE q.id = ?
         LIMIT 1'
    );
    if (!$stmt) {
        throw new Exception('Unable to prepare quotation lookup.', 500);
    }
    $stmt->bind_param('i', $quotationId);
    $stmt->execute();
    $quotation = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$quotation) {
        throw new Exception('Quotation not found.', 404);
    }

    $previousStatus = $quotation['status'];
    if ($previousStatus === $status) {
        http_response_code(200);
        echo json_encode([
            'status' => 'success',
            'message' => 'Quotation status is already up to date.',
            'data' => [
                'id' => $quotationId,
                'quotation_number' => $quotation['quotation_number'],
                'status' => $status,
            ],
        ]);
        exit;
    }

    $update = $conn->prepare('UPDATE quotations SET status = ?, updated_at = NOW() WHERE id = ?');
    if (!$update) {
        throw new Exception('Unable to prepare quotation status update.', 500);
    }
    $update->bind_param('si', $status, $quotationId);
    $update->execute();
    $update->close();

    $description = sprintf(
        '%s updated quotation %s for %s from %s to %s%s.',
        $userData['email'] ?? 'A user',
        $quotation['quotation_number'],
        $quotation['company_name'],
        $previousStatus,
        $status,
        $reason !== '' ? ' Reason: ' . $reason : ''
    );

    $audit = $conn->prepare(
        'INSERT INTO activity_log (user_id, action, model_type, model_id, description, ip_address)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    if ($audit) {
        $action = 'quotation.status_updated';
        $modelType = 'Quotation';
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        $audit->bind_param('ississ', $loggedInUserId, $action, $modelType, $quotationId, $description, $ipAddress);
        $audit->execute();
        $audit->close();
    }

    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'message' => 'Quotation status updated successfully.',
        'data' => [
            'id' => $quotationId,
            'quotation_number' => $quotation['quotation_number'],
            'previous_status' => $previousStatus,
            'status' => $status,
        ],
    ]);
} catch (Exception $e) {
    error_log('Quotation Status Update Error: ' . $e->getMessage());
    $code = (int) $e->getCode();
    http_response_code(($code >= 400 && $code < 600) ? $code : 500);
    echo json_encode([
        'status' => 'failed',
        'message' => $e->getMessage(),
    ]);
}
?>
