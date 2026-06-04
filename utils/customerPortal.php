<?php
// utils/customerPortal.php
// Secure customer-facing portal links for invoices, delivery notes, payment requests and receipts.

declare(strict_types=1);

require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/roles.php';
require_once __DIR__ . '/paymentLinks.php';

function customerPortalReference(int $invoiceId): string
{
    return 'CPL-' . date('Y') . '-' . $invoiceId . '-' . strtoupper(bin2hex(random_bytes(5)));
}

function customerPortalToken(): string
{
    return randomUrlSafeToken(36);
}

function customerPortalTokenHash(string $token): string
{
    return hash('sha256', trim($token));
}

function customerPortalFrontendUrl(string $token): ?string
{
    $frontendUrl = rtrim((string) (config('FRONTEND_URL', '') ?? ''), '/');
    if ($frontendUrl === '') {
        return null;
    }

    return $frontendUrl . '/customer-portal/' . rawurlencode($token);
}

function customerPortalStatusLabel(string $status): string
{
    return match ($status) {
        'active' => 'Active',
        'expired' => 'Expired',
        'revoked' => 'Revoked',
        default => ucfirst($status),
    };
}

function customerPortalLinkResponse(array $row, ?string $freshToken = null): array
{
    $status = (string) $row['status'];
    $expiresAt = (string) ($row['expires_at'] ?? '');
    $isExpired = false;

    if ($status === 'active' && $expiresAt !== '') {
        try {
            $isExpired = (new DateTime($expiresAt)) < new DateTime('now');
        } catch (Throwable $e) {
            $isExpired = false;
        }
    }

    $effectiveStatus = $isExpired ? 'expired' : $status;

    return [
        'id' => (int) $row['id'],
        'client_id' => (int) $row['client_id'],
        'client_name' => $row['client_name'] ?? null,
        'client_email' => $row['client_email'] ?? null,
        'invoice_id' => (int) $row['invoice_id'],
        'invoice_number' => $row['invoice_number'] ?? null,
        'invoice_status' => $row['invoice_status'] ?? null,
        'invoice_total' => isset($row['invoice_total']) ? (float) $row['invoice_total'] : null,
        'balance_due' => isset($row['balance_due']) ? (float) $row['balance_due'] : null,
        'currency' => $row['currency'] ?? null,
        'reference' => $row['reference'],
        'status' => $effectiveStatus,
        'status_label' => customerPortalStatusLabel($effectiveStatus),
        'expires_at' => $row['expires_at'] ?? null,
        'last_accessed_at' => $row['last_accessed_at'] ?? null,
        'access_count' => (int) ($row['access_count'] ?? 0),
        'last_sent_at' => $row['last_sent_at'] ?? null,
        'email_count' => (int) ($row['email_count'] ?? 0),
        'created_by_name' => $row['created_by_name'] ?? null,
        'created_at' => $row['created_at'] ?? null,
        'updated_at' => $row['updated_at'] ?? null,
        'public_url' => $freshToken ? customerPortalFrontendUrl($freshToken) : null,
    ];
}

function fetchCustomerPortalCompanySettings(mysqli $conn): array
{
    $result = $conn->query(
        'SELECT company_name, address, city, state, country, phone, email, website, logo_path,
                bank_name, account_name, account_number, bank_branch, legal_footer
         FROM company_settings
         ORDER BY id ASC
         LIMIT 1'
    );

    return $result ? ($result->fetch_assoc() ?: []) : [];
}

function fetchCustomerPortalLink(mysqli $conn, int $id): ?array
{
    $stmt = $conn->prepare(
        'SELECT cpl.*, i.invoice_number, i.status AS invoice_status, i.total_amount AS invoice_total,
                i.balance_due, i.currency, c.company_name AS client_name, c.email AS client_email,
                u.name AS created_by_name
         FROM customer_portal_links cpl
         JOIN invoices i ON i.id = cpl.invoice_id
         JOIN clients c ON c.id = cpl.client_id
         LEFT JOIN users u ON u.id = cpl.created_by
         WHERE cpl.id = ?
         LIMIT 1'
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    return $row;
}

function fetchCustomerPortalInvoiceForStaff(mysqli $conn, int $invoiceId, array $user): array
{
    $stmt = $conn->prepare(
        'SELECT i.id, i.invoice_number, i.client_id, i.status, i.total_amount, i.amount_paid,
                i.credited_amount, i.refunded_amount, i.balance_due, i.currency, i.created_by,
                c.company_name AS client_name, c.email AS client_email
         FROM invoices i
         JOIN clients c ON c.id = i.client_id
         WHERE i.id = ?
         LIMIT 1'
    );
    $stmt->bind_param('i', $invoiceId);
    $stmt->execute();
    $invoice = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$invoice) {
        throw new Exception('Invoice not found.', 404);
    }

    if (($user['role'] ?? '') === ROLE_SALES && (int) $invoice['created_by'] !== (int) $user['id']) {
        throw new Exception('You do not have permission to share this invoice portal.', 403);
    }

    if (in_array((string) $invoice['status'], ['draft', 'cancelled', 'reversed'], true)) {
        throw new Exception('Customer portal links can only be created for finalized customer-facing invoices.', 409);
    }

    return $invoice;
}

function createOrRefreshCustomerPortalLink(mysqli $conn, array $invoice, array $user, int $expiresInDays = 30): array
{
    $expiresInDays = max(1, min(90, $expiresInDays));
    $token = customerPortalToken();
    $tokenHash = customerPortalTokenHash($token);
    $expiresAt = (new DateTime('now'))->modify("+{$expiresInDays} days")->format('Y-m-d H:i:s');
    $invoiceId = (int) $invoice['id'];
    $clientId = (int) $invoice['client_id'];
    $userId = (int) $user['id'];

    $existingStmt = $conn->prepare(
        "SELECT id FROM customer_portal_links
         WHERE invoice_id = ? AND status = 'active'
         ORDER BY id DESC
         LIMIT 1"
    );
    $existingStmt->bind_param('i', $invoiceId);
    $existingStmt->execute();
    $existing = $existingStmt->get_result()->fetch_assoc();
    $existingStmt->close();

    if ($existing) {
        $linkId = (int) $existing['id'];
        $update = $conn->prepare(
            "UPDATE customer_portal_links
             SET token_hash = ?, expires_at = ?, status = 'active', updated_by = ?, updated_at = NOW()
             WHERE id = ?"
        );
        $update->bind_param('ssii', $tokenHash, $expiresAt, $userId, $linkId);
        $update->execute();
        $update->close();
    } else {
        $reference = customerPortalReference($invoiceId);
        $insert = $conn->prepare(
            'INSERT INTO customer_portal_links
                (client_id, invoice_id, reference, token_hash, status, expires_at, created_by, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $status = 'active';
        $insert->bind_param('iissssii', $clientId, $invoiceId, $reference, $tokenHash, $status, $expiresAt, $userId, $userId);
        $insert->execute();
        $linkId = (int) $insert->insert_id;
        $insert->close();
    }

    $link = fetchCustomerPortalLink($conn, $linkId);
    if (!$link) {
        throw new Exception('Customer portal link could not be prepared.', 500);
    }

    return [
        'link' => $link,
        'token' => $token,
        'public_url' => customerPortalFrontendUrl($token),
    ];
}

function logCustomerPortalAction(mysqli $conn, int $userId, string $action, int $linkId, string $description, array $properties = []): void
{
    $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'system'), 0, 45);
    $propertiesJson = $properties ? json_encode($properties, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
    $modelType = 'CustomerPortalLink';

    $stmt = $conn->prepare(
        'INSERT INTO activity_log
            (user_id, action, model_type, model_id, description, properties, ip_address)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('ississs', $userId, $action, $modelType, $linkId, $description, $propertiesJson, $ip);
    $stmt->execute();
    $stmt->close();
}

function safeCustomerPortalString(?string $value): string
{
    return trim((string) ($value ?? ''));
}
