<?php
// routes/deliveryNotes/updateDeliveryNoteStatus.php
// PUT /delivery-notes/{id}/status

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/roles.php';
require_once __DIR__ . '/../../utils/deliveryNotes.php';

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Africa/Lagos');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'PUT') {
        throw new Exception('Method Not Allowed', 405);
    }

    $user = authenticateUser();
    $userId = (int) $user['id'];
    $role = (string) $user['role'];
    $email = (string) $user['email'];

    requireRole($user, [ROLE_SUPER_ADMIN, ROLE_ADMIN, ROLE_SALES], 'Unauthorized: You cannot update delivery notes.');

    if (empty($_GET['id']) || !is_numeric($_GET['id'])) {
        throw new Exception('A valid delivery note ID is required.', 400);
    }

    $deliveryNoteId = (int) $_GET['id'];
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        throw new Exception('Invalid or missing JSON payload.', 400);
    }

    $newStatus = strtolower(trim((string) ($data['status'] ?? '')));
    if (!in_array($newStatus, ['dispatched', 'delivered', 'cancelled'], true)) {
        throw new Exception('Status must be dispatched, delivered or cancelled.', 422);
    }

    $stmt = $conn->prepare(
        'SELECT dn.id, dn.delivery_note_number, dn.status, dn.created_by, dn.invoice_id,
                i.invoice_number, i.created_by AS invoice_created_by, c.company_name AS client_name
         FROM delivery_notes dn
         JOIN invoices i ON i.id = dn.invoice_id
         JOIN clients c ON c.id = dn.client_id
         WHERE dn.id = ?
         LIMIT 1'
    );
    $stmt->bind_param('i', $deliveryNoteId);
    $stmt->execute();
    $note = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$note) {
        throw new Exception('Delivery note not found.', 404);
    }

    if ($role === ROLE_SALES && (int) $note['invoice_created_by'] !== $userId && (int) $note['created_by'] !== $userId) {
        throw new Exception('Unauthorized: You cannot update this delivery note.', 403);
    }

    $currentStatus = (string) $note['status'];
    if (in_array($currentStatus, ['delivered', 'cancelled'], true)) {
        throw new Exception('Delivered or cancelled delivery notes cannot be updated.', 409);
    }

    if ($newStatus === 'dispatched' && $currentStatus !== 'draft') {
        throw new Exception('Only draft delivery notes can be marked as dispatched.', 409);
    }

    if ($newStatus === 'delivered' && !in_array($currentStatus, ['draft', 'dispatched'], true)) {
        throw new Exception('Only draft or dispatched delivery notes can be marked as delivered.', 409);
    }

    $dispatchDate = trimNullable($data['dispatch_date'] ?? null);
    $receiverName = trimNullable($data['receiver_name'] ?? null);
    $notes = trimNullable($data['notes'] ?? null);
    $today = date('Y-m-d');
    if ($dispatchDate !== null) {
        $dispatchDate = validYmdOrDefault($dispatchDate, $today);
    }

    if ($newStatus === 'delivered' && $receiverName === null) {
        throw new Exception('Receiver name is required before marking a delivery note as delivered.', 422);
    }

    $conn->begin_transaction();

    try {
        if ($newStatus === 'dispatched') {
            $effectiveDispatchDate = $dispatchDate ?: $today;
            $update = $conn->prepare(
                'UPDATE delivery_notes
                 SET status = "dispatched", dispatch_date = ?, notes = COALESCE(?, notes)
                 WHERE id = ? AND status = "draft"'
            );
            $update->bind_param('ssi', $effectiveDispatchDate, $notes, $deliveryNoteId);
        } elseif ($newStatus === 'delivered') {
            $effectiveDispatchDate = $dispatchDate ?: ($currentStatus === 'draft' ? $today : null);
            if ($currentStatus === 'draft') {
                $update = $conn->prepare(
                    'UPDATE delivery_notes
                     SET status = "delivered", dispatch_date = ?, delivered_at = NOW(), receiver_name = ?, notes = COALESCE(?, notes)
                     WHERE id = ? AND status = "draft"'
                );
                $update->bind_param('sssi', $effectiveDispatchDate, $receiverName, $notes, $deliveryNoteId);
            } else {
                $update = $conn->prepare(
                    'UPDATE delivery_notes
                     SET status = "delivered", delivered_at = NOW(), receiver_name = ?, notes = COALESCE(?, notes)
                     WHERE id = ? AND status = "dispatched"'
                );
                $update->bind_param('ssi', $receiverName, $notes, $deliveryNoteId);
            }
        } else {
            if (!in_array($role, [ROLE_SUPER_ADMIN, ROLE_ADMIN], true)) {
                throw new Exception('Unauthorized: Only Admins can cancel delivery notes.', 403);
            }
            $update = $conn->prepare(
                'UPDATE delivery_notes
                 SET status = "cancelled", cancelled_at = NOW(), notes = COALESCE(?, notes)
                 WHERE id = ? AND status IN ("draft", "dispatched")'
            );
            $update->bind_param('si', $notes, $deliveryNoteId);
        }

        $update->execute();
        if ($update->affected_rows === 0) {
            throw new Exception('Delivery note status could not be updated. It may have changed already.', 409);
        }
        $update->close();

        logDeliveryNoteAction(
            $conn,
            $userId,
            'delivery_note.status_updated',
            $deliveryNoteId,
            "{$email} changed delivery note {$note['delivery_note_number']} from " . deliveryNoteStatusLabel($currentStatus) . ' to ' . deliveryNoteStatusLabel($newStatus) . '.',
            ['previous_status' => $currentStatus, 'new_status' => $newStatus]
        );

        $conn->commit();

        http_response_code(200);
        echo json_encode([
            'status' => 'success',
            'message' => 'Delivery note status updated successfully.',
            'data' => [
                'id' => $deliveryNoteId,
                'delivery_note_number' => $note['delivery_note_number'],
                'previous_status' => $currentStatus,
                'status' => $newStatus,
            ],
        ]);
    } catch (Throwable $inner) {
        $conn->rollback();
        throw $inner;
    }
} catch (Throwable $error) {
    error_log('Update Delivery Note Status Error: ' . $error->getMessage());
    $code = (int) $error->getCode();
    $clientError = in_array($code, [400, 403, 404, 405, 409, 422], true);

    http_response_code($clientError ? $code : 500);
    echo json_encode([
        'status' => 'failed',
        'message' => $clientError ? $error->getMessage() : 'Delivery note status could not be updated right now.',
    ]);
}
