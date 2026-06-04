<?php
// routes/quotations/search.php
// GET /quotations/search - lightweight quotation lookup used by global search.

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';

header('Content-Type: application/json');

date_default_timezone_set('Africa/Lagos');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception('Route not found', 400);
    }

    $userData = authenticateUser();
    $loggedInUserId = (int) ($userData['id'] ?? 0);
    $loggedInUserRole = (string) ($userData['role'] ?? '');

    if (!in_array($loggedInUserRole, ['super_admin', 'admin', 'sales', 'accounting'], true)) {
        throw new Exception('Unauthorized: You do not have permission to search quotations.', 403);
    }

    $search = trim((string) ($_GET['search'] ?? $_GET['q'] ?? ''));
    $limit = isset($_GET['limit']) ? max(1, min(25, (int) $_GET['limit'])) : 10;

    if ($search === '') {
        http_response_code(200);
        echo json_encode([
            'status' => 'success',
            'data' => [],
        ]);
        exit;
    }

    $sql = "
        SELECT
            q.id,
            q.quotation_number,
            q.client_id,
            c.company_name AS client_name,
            q.issue_date,
            q.expiry_date,
            q.currency,
            q.total_amount,
            q.status,
            q.created_by,
            u.name AS created_by_name
        FROM quotations q
        INNER JOIN clients c ON c.id = q.client_id
        LEFT JOIN users u ON u.id = q.created_by
        WHERE (
            q.quotation_number LIKE ?
            OR c.company_name LIKE ?
            OR c.email LIKE ?
            OR c.phone LIKE ?
        )
    ";

    $like = '%' . $search . '%';
    $params = [$like, $like, $like, $like];
    $types = 'ssss';

    if ($loggedInUserRole === 'sales') {
        $sql .= ' AND q.created_by = ?';
        $params[] = $loggedInUserId;
        $types .= 'i';
    }

    $sql .= ' ORDER BY q.created_at DESC, q.id DESC LIMIT ?';
    $params[] = $limit;
    $types .= 'i';

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Unable to prepare quotation search.', 500);
    }

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    $quotations = [];
    while ($row = $result->fetch_assoc()) {
        $quotations[] = [
            'id' => (int) $row['id'],
            'quotation_number' => $row['quotation_number'],
            'client_id' => (int) $row['client_id'],
            'client_name' => $row['client_name'],
            'client' => [
                'id' => (int) $row['client_id'],
                'company_name' => $row['client_name'],
            ],
            'issue_date' => $row['issue_date'],
            'expiry_date' => $row['expiry_date'],
            'currency' => $row['currency'],
            'total_amount' => (float) $row['total_amount'],
            'status' => $row['status'],
            'created_by' => (int) $row['created_by'],
            'created_by_name' => $row['created_by_name'],
        ];
    }
    $stmt->close();

    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'data' => $quotations,
    ]);
} catch (Exception $e) {
    error_log('Quotation Search Error: ' . $e->getMessage());
    $code = (int) $e->getCode();
    http_response_code(($code >= 400 && $code < 600) ? $code : 500);
    echo json_encode([
        'status' => 'failed',
        'message' => $e->getMessage(),
    ]);
}
?>
