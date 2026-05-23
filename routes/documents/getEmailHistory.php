<?php
// routes/documents/getEmailHistory.php
// GET /documents/{id}/email-history?type=quotation|proforma|invoice|receipt|credit_note
// Returns recent send attempts for a document to authenticated users permitted to view it.

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        throw new Exception('Method Not Allowed', 405);
    }

    $user = authenticateUser();
    $userId = (int) $user['id'];
    $role = (string) $user['role'];

    if (!in_array($role, ['super_admin', 'admin', 'sales', 'accounting'], true)) {
        throw new Exception('Unauthorized: You cannot view document email history.', 403);
    }

    if (empty($_GET['id']) || !is_numeric($_GET['id'])) {
        throw new Exception('A valid document ID is required.', 400);
    }

    $documentId = (int) $_GET['id'];
    $documentType = strtolower(trim((string) ($_GET['type'] ?? '')));

    $sources = [
        'quotation' => ['table' => 'quotations', 'number' => 'quotation_number', 'owner' => 'created_by'],
        'proforma'  => ['table' => 'proforma_invoices', 'number' => 'proforma_number', 'owner' => 'created_by'],
        'invoice'   => ['table' => 'invoices', 'number' => 'invoice_number', 'owner' => 'created_by'],
        'receipt'   => ['table' => 'payment_receipts', 'number' => 'receipt_number', 'owner' => 'issued_by'],
        'credit_note' => ['table' => 'credit_notes', 'number' => 'credit_note_number', 'owner' => 'issued_by'],
    ];

    if (!isset($sources[$documentType])) {
        throw new Exception('A valid document type is required.', 400);
    }

    if ($documentType === 'credit_note' && $role !== 'super_admin') {
        throw new Exception('Unauthorized: Only the Super Admin can view credit note delivery history.', 403);
    }

    $source = $sources[$documentType];
    $accessStatement = $conn->prepare(
        "SELECT id, {$source['owner']} AS created_by, {$source['number']} AS document_number
         FROM {$source['table']}
         WHERE id = ?
         LIMIT 1"
    );
    $accessStatement->bind_param('i', $documentId);
    $accessStatement->execute();
    $document = $accessStatement->get_result()->fetch_assoc();
    $accessStatement->close();

    if (!$document) {
        throw new Exception('Document not found.', 404);
    }

    if ($role === 'sales' && (int) $document['created_by'] !== $userId) {
        throw new Exception('Unauthorized: You cannot view this document email history.', 403);
    }

    $statement = $conn->prepare(
        'SELECT l.id, l.recipient_email, l.attachment_name, l.attachment_size,
                l.delivery_status, l.sent_at, u.name AS sent_by_name
         FROM document_email_logs l
         LEFT JOIN users u ON u.id = l.sent_by
         WHERE l.document_type = ? AND l.document_id = ?
         ORDER BY l.sent_at DESC, l.id DESC
         LIMIT 10'
    );
    $statement->bind_param('si', $documentType, $documentId);
    $statement->execute();
    $result = $statement->get_result();

    $history = [];
    while ($row = $result->fetch_assoc()) {
        $history[] = [
            'id' => (int) $row['id'],
            'recipient_email' => $row['recipient_email'],
            'attachment_name' => $row['attachment_name'],
            'attachment_size' => (int) ($row['attachment_size'] ?? 0),
            'delivery_status' => $row['delivery_status'],
            'sent_by_name' => $row['sent_by_name'],
            'sent_at' => $row['sent_at'],
        ];
    }
    $statement->close();

    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'message' => 'Document email history fetched successfully.',
        'data' => $history,
    ]);
} catch (Throwable $error) {
    error_log('Get Document Email History Error: ' . $error->getMessage());

    $code = (int) $error->getCode();
    $clientError = in_array($code, [400, 403, 404, 405], true);

    http_response_code($clientError ? $code : 500);
    echo json_encode([
        'status' => 'failed',
        'message' => $clientError
            ? $error->getMessage()
            : 'Document email history could not be loaded right now.',
    ]);
}
