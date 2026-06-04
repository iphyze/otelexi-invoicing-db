<?php
// utils/deliveryNotes.php
// Delivery note numbering, validation and audit helpers.

declare(strict_types=1);

function nextDeliveryNoteNumber(mysqli $conn): string
{
    $docType = 'delivery_note';
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

    return sprintf('DN/%d/%03d', $year, $next);
}

function trimNullable(mixed $value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }

    $trimmed = trim((string) $value);
    return $trimmed === '' ? null : $trimmed;
}

function validYmdOrDefault(?string $value, string $default): string
{
    $value = trim((string) $value);
    if ($value !== '') {
        $date = DateTime::createFromFormat('Y-m-d', $value);
        if ($date && $date->format('Y-m-d') === $value) {
            return $value;
        }
    }

    return $default;
}

function deliveryNoteStatusLabel(string $status): string
{
    return match ($status) {
        'draft' => 'Draft',
        'dispatched' => 'Dispatched',
        'delivered' => 'Delivered',
        'cancelled' => 'Cancelled',
        default => ucfirst($status),
    };
}

function logDeliveryNoteAction(
    mysqli $conn,
    int $userId,
    string $action,
    int $deliveryNoteId,
    string $description,
    array $properties = []
): void {
    $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'system'), 0, 45);
    $propertiesJson = $properties ? json_encode($properties, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
    $modelType = 'DeliveryNote';

    $stmt = $conn->prepare(
        'INSERT INTO activity_log
            (user_id, action, model_type, model_id, description, properties, ip_address)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('ississs', $userId, $action, $modelType, $deliveryNoteId, $description, $propertiesJson, $ip);
    $stmt->execute();
    $stmt->close();
}
