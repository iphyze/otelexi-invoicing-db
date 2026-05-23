<?php
// utils/inventory.php
// Controlled stock adjustment and movement helpers.

declare(strict_types=1);

/**
 * Reserve the next yearly stock adjustment number.
 * Must be called while the caller transaction is active.
 */
function nextStockAdjustmentNumber(mysqli $conn): string
{
    $docType = 'stock_adjustment';
    $year = (int) date('Y');

    $ensure = $conn->prepare(
        'INSERT INTO document_number_sequences (doc_type, year, last_sequence)
         VALUES (?, ?, 0)
         ON DUPLICATE KEY UPDATE last_sequence = last_sequence'
    );
    $ensure->bind_param('si', $docType, $year);
    $ensure->execute();
    $ensure->close();

    $select = $conn->prepare(
        'SELECT last_sequence FROM document_number_sequences
         WHERE doc_type = ? AND year = ? FOR UPDATE'
    );
    $select->bind_param('si', $docType, $year);
    $select->execute();
    $next = (int) ($select->get_result()->fetch_assoc()['last_sequence'] ?? 0) + 1;
    $select->close();

    $update = $conn->prepare(
        'UPDATE document_number_sequences SET last_sequence = ?
         WHERE doc_type = ? AND year = ?'
    );
    $update->bind_param('isi', $next, $docType, $year);
    $update->execute();
    $update->close();

    return sprintf('ADJ/%d/%03d', $year, $next);
}

/**
 * Add an audit event for an inventory action.
 */
function logInventoryAction(
    mysqli $conn,
    int $userId,
    string $action,
    string $modelType,
    int $modelId,
    string $description,
    array $properties = []
): void {
    $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'system'), 0, 45);
    $propertiesJson = $properties ? json_encode($properties, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

    $stmt = $conn->prepare(
        'INSERT INTO activity_log
            (user_id, action, model_type, model_id, description, properties, ip_address)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('ississs', $userId, $action, $modelType, $modelId, $description, $propertiesJson, $ip);
    $stmt->execute();
    $stmt->close();
}
