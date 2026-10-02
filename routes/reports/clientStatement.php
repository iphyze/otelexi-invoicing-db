<?php
// routes/reports/clientStatement.php
// Client receivables statement with historical opening balance and running ledger.

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/roles.php';
require_once __DIR__ . '/../../utils/uploadStorage.php';

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Africa/Lagos');

/**
 * GET /reports/client-statement
 *
 * Query params:
 *   client_id  required
 *   from       YYYY-MM-DD, defaults to first day of current year
 *   to         YYYY-MM-DD, defaults to today
 *   currency   NGN|USD, optional; defaults to the client's preferred currency
 *
 * Accounting treatment:
 *   Invoice      = Debit
 *   Refund       = Debit
 *   Payment      = Credit
 *   Credit Note  = Credit
 *
 * Draft, cancelled and reversed invoices do not form part of the statement.
 * Opening balance is calculated from valid activity strictly before `from`.
 */

function clientStatementDate(string $value, string $label, string $default): string
{
    $value = trim($value);
    if ($value === '') {
        return $default;
    }

    $date = DateTime::createFromFormat('!Y-m-d', $value);
    $errors = DateTime::getLastErrors();
    $hasErrors = is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0);

    if (!$date || $hasErrors || $date->format('Y-m-d') !== $value) {
        throw new Exception("{$label} must be a valid date in YYYY-MM-DD format.", 422);
    }

    return $value;
}

function clientStatementMoney(float $amount): float
{
    return round($amount, 2);
}

function clientStatementScalar(mysqli $conn, string $sql, string $types, array $params): float
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare client statement calculation.');
    }

    if ($types !== '') {
        $bindArgs = [$types];
        foreach ($params as $index => $value) {
            $params[$index] = $value;
            $bindArgs[] = &$params[$index];
        }
        call_user_func_array([$stmt, 'bind_param'], $bindArgs);
    }

    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return clientStatementMoney((float) ($row['total'] ?? 0));
}

function clientStatementPaymentMethodLabel(?string $method): string
{
    $labels = [
        'bank_transfer' => 'Bank Transfer',
        'cash' => 'Cash',
        'cheque' => 'Cheque',
        'pos' => 'POS',
        'online' => 'Online',
        'other' => 'Other',
    ];

    $method = strtolower(trim((string) $method));
    return $labels[$method] ?? ($method !== '' ? ucwords(str_replace('_', ' ', $method)) : 'Payment');
}

function clientStatementBalancePosition(float $balance): string
{
    if (abs($balance) < 0.005) {
        return 'settled';
    }

    return $balance > 0 ? 'receivable' : 'client_credit';
}


function clientStatementExcelLogoPath(?string $logoUrl): ?string
{
    $logoUrl = trim((string) ($logoUrl ?? ''));

    if ($logoUrl !== '') {
        $path = parse_url($logoUrl, PHP_URL_PATH);
        if (is_string($path) && preg_match('#/uploads/(.+)$#', $path, $matches)) {
            try {
                $candidate = uploadStorageDirectory($matches[1]);
                $extension = strtolower(pathinfo($candidate, PATHINFO_EXTENSION));
                if (is_file($candidate) && in_array($extension, ['png', 'jpg', 'jpeg'], true)) {
                    return $candidate;
                }
            } catch (Throwable $e) {
                // Fall through to the bundled Otelex brand logo below.
            }
        }
    }

    $fallback = dirname(__DIR__, 2) . '/assets/images/otelex-logo.png';
    return is_file($fallback) ? $fallback : null;
}

function clientStatementExcelMoneyFormat(string $currency): string
{
    return strtoupper($currency) === 'USD'
        ? '$#,##0.00;[Red]-$#,##0.00;$0.00'
        : '₦#,##0.00;[Red]-₦#,##0.00;₦0.00';
}

function clientStatementExcelTransactionMoneyFormat(string $currency): string
{
    return strtoupper($currency) === 'USD'
        ? '$#,##0.00;[Red]-$#,##0.00;-'
        : '₦#,##0.00;[Red]-₦#,##0.00;-';
}

function clientStatementExportExcel(array $statement): void
{
    $company = $statement['company'] ?? [];
    $client = $statement['client'] ?? [];
    $period = $statement['period'] ?? [];
    $summary = $statement['summary'] ?? [];
    $ledger = $statement['ledger'] ?? [];
    $currency = strtoupper((string) ($period['currency'] ?? 'NGN'));

    $blue = '1A56DB';
    $blueDark = '153EAA';
    $navy = '0F172A';
    $muted = '64748B';
    $border = 'DCE5F2';
    $lightBlue = 'EFF6FF';
    $lightGray = 'F8FAFC';
    $green = '059669';
    $red = 'DC2626';
    $white = 'FFFFFF';

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Client Statement');
    $sheet->setShowGridlines(false);

    $spreadsheet->getProperties()
        ->setCreator((string) ($company['company_name'] ?? 'Otelex'))
        ->setTitle('Client Statement - ' . (string) ($client['company_name'] ?? 'Client'))
        ->setSubject('Client receivables statement')
        ->setDescription('Client statement generated from Otelex.');

    foreach (['A' => 14, 'B' => 22, 'C' => 20, 'D' => 46, 'E' => 18, 'F' => 18, 'G' => 19] as $column => $width) {
        $sheet->getColumnDimension($column)->setWidth($width);
    }

    for ($row = 1; $row <= 4; $row++) {
        $sheet->getRowDimension($row)->setRowHeight($row === 1 ? 26 : 22);
    }

    $logoPath = clientStatementExcelLogoPath($company['logo_path'] ?? null);
    if ($logoPath) {
        $drawing = new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing();
        $drawing->setName('Otelex Logo');
        $drawing->setDescription('Company logo');
        $drawing->setPath($logoPath);
        $drawing->setCoordinates('A1');
        $drawing->setHeight(56);
        $drawing->setOffsetX(4);
        $drawing->setOffsetY(4);
        $drawing->setWorksheet($sheet);
    } else {
        $sheet->mergeCells('A1:B2');
        $sheet->setCellValue('A1', (string) ($company['company_name'] ?? 'OTelex'));
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => $blue]],
            'alignment' => ['vertical' => 'center'],
        ]);
    }

    $sheet->mergeCells('C1:G1');
    $sheet->setCellValue('C1', 'CLIENT STATEMENT');
    $sheet->getStyle('C1')->applyFromArray([
        'font' => ['bold' => true, 'size' => 20, 'color' => ['rgb' => $blue]],
        'alignment' => ['horizontal' => 'right', 'vertical' => 'center'],
    ]);

    $sheet->mergeCells('C2:G2');
    $sheet->setCellValue('C2', (string) ($company['company_name'] ?? 'Otelex Hospitality Supplies Ltd'));
    $sheet->getStyle('C2')->applyFromArray([
        'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => $navy]],
        'alignment' => ['horizontal' => 'right'],
    ]);

    $companyAddress = array_filter([
        trim((string) ($company['address'] ?? '')),
        trim((string) ($company['city'] ?? '')),
        trim((string) ($company['state'] ?? '')),
        trim((string) ($company['country'] ?? '')),
    ]);
    $contactLine = implode(', ', $companyAddress);
    $contactBits = array_filter([
        trim((string) ($company['email'] ?? '')),
        trim((string) ($company['phone'] ?? '')),
        trim((string) ($company['website'] ?? '')),
    ]);
    if ($contactBits) {
        $contactLine .= ($contactLine !== '' ? '  •  ' : '') . implode('  •  ', $contactBits);
    }

    $sheet->mergeCells('C3:G4');
    $sheet->setCellValue('C3', $contactLine);
    $sheet->getStyle('C3:G4')->applyFromArray([
        'font' => ['size' => 9, 'color' => ['rgb' => $muted]],
        'alignment' => ['horizontal' => 'right', 'vertical' => 'top', 'wrapText' => true],
        'borders' => ['bottom' => ['borderStyle' => 'thin', 'color' => ['rgb' => $blue]]],
    ]);
    $sheet->getStyle('A4:B4')->getBorders()->getBottom()->setBorderStyle('thin')->getColor()->setRGB($blue);

    // Client and statement metadata.
    $sheet->mergeCells('A6:D6');
    $sheet->setCellValue('A6', 'STATEMENT FOR');
    $sheet->mergeCells('E6:G6');
    $sheet->setCellValue('E6', 'STATEMENT DETAILS');
    $sheet->getStyle('A6:G6')->applyFromArray([
        'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => $lightBlue]],
        'font' => ['bold' => true, 'size' => 9, 'color' => ['rgb' => $blue]],
        'alignment' => ['vertical' => 'center'],
        'borders' => ['bottom' => ['borderStyle' => 'thin', 'color' => ['rgb' => $border]]],
    ]);

    $sheet->mergeCells('A7:D7');
    $sheet->setCellValue('A7', (string) ($client['company_name'] ?? 'Client'));
    $sheet->getStyle('A7')->getFont()->setBold(true)->setSize(13)->getColor()->setRGB($navy);

    $clientAddress = array_filter([
        trim((string) ($client['address'] ?? '')),
        trim((string) ($client['city'] ?? '')),
        trim((string) ($client['state'] ?? '')),
        trim((string) ($client['country'] ?? '')),
    ]);
    $clientLine = implode(', ', $clientAddress);
    $clientContacts = array_filter([
        trim((string) ($client['email'] ?? '')),
        trim((string) ($client['phone'] ?? '')),
    ]);
    if ($clientContacts) {
        $clientLine .= ($clientLine !== '' ? '  •  ' : '') . implode('  •  ', $clientContacts);
    }
    $sheet->mergeCells('A8:D9');
    $sheet->setCellValue('A8', $clientLine !== '' ? $clientLine : 'No additional client contact details on file.');
    $sheet->getStyle('A8:D9')->applyFromArray([
        'font' => ['size' => 9, 'color' => ['rgb' => $muted]],
        'alignment' => ['vertical' => 'top', 'wrapText' => true],
    ]);

    $sheet->setCellValue('E7', 'Period');
    $sheet->setCellValue('F7', (string) ($period['from'] ?? ''));
    $sheet->mergeCells('F7:G7');
    $sheet->setCellValue('F7', (string) ($period['from'] ?? '') . ' to ' . (string) ($period['to'] ?? ''));
    $sheet->setCellValue('E8', 'Currency');
    $sheet->mergeCells('F8:G8');
    $sheet->setCellValue('F8', $currency);
    $sheet->setCellValue('E9', 'Transactions');
    $sheet->mergeCells('F9:G9');
    $sheet->setCellValue('F9', (int) ($summary['transaction_count'] ?? 0));
    $sheet->getStyle('E7:E9')->getFont()->setBold(true)->getColor()->setRGB($muted);
    $sheet->getStyle('F7:G9')->getFont()->setBold(true)->getColor()->setRGB($navy);

    // Summary block.
    $summaryRow = 11;
    $summaryItems = [
        ['Opening Balance', (float) ($summary['opening_balance'] ?? 0), $blue],
        ['Total Debit', (float) ($summary['total_debits'] ?? 0), $red],
        ['Total Credit', (float) ($summary['total_credits'] ?? 0), $green],
        ['Closing Balance', (float) ($summary['closing_balance'] ?? 0), $blueDark],
    ];
    $summaryCols = [['A', 'B'], ['C', 'D'], ['E', 'E'], ['F', 'G']];
    foreach ($summaryItems as $index => $item) {
        [$startCol, $endCol] = $summaryCols[$index];
        $sheet->mergeCells("{$startCol}{$summaryRow}:{$endCol}{$summaryRow}");
        $sheet->mergeCells("{$startCol}" . ($summaryRow + 1) . ":{$endCol}" . ($summaryRow + 1));
        $sheet->setCellValue("{$startCol}{$summaryRow}", $item[0]);
        $sheet->setCellValue("{$startCol}" . ($summaryRow + 1), $item[1]);
        $sheet->getStyle("{$startCol}{$summaryRow}:{$endCol}" . ($summaryRow + 1))->applyFromArray([
            'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => $lightGray]],
            'borders' => ['outline' => ['borderStyle' => 'thin', 'color' => ['rgb' => $border]]],
        ]);
        $sheet->getStyle("{$startCol}{$summaryRow}:{$endCol}{$summaryRow}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 9, 'color' => ['rgb' => $muted]],
            'alignment' => ['horizontal' => 'center'],
        ]);
        $sheet->getStyle("{$startCol}" . ($summaryRow + 1) . ":{$endCol}" . ($summaryRow + 1))->applyFromArray([
            'font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => $item[2]]],
            'alignment' => ['horizontal' => 'center'],
        ]);
        $sheet->getStyle("{$startCol}" . ($summaryRow + 1))->getNumberFormat()->setFormatCode(clientStatementExcelMoneyFormat($currency));
    }
    $sheet->getRowDimension(11)->setRowHeight(20);
    $sheet->getRowDimension(12)->setRowHeight(24);

    // Ledger table.
    $headerRow = 14;
    $headers = ['Date', 'Reference', 'Transaction', 'Description', 'Debit', 'Credit', 'Balance'];
    $sheet->fromArray($headers, null, "A{$headerRow}");
    $sheet->getStyle("A{$headerRow}:G{$headerRow}")->applyFromArray([
        'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => $blue]],
        'font' => ['bold' => true, 'size' => 9, 'color' => ['rgb' => $white]],
        'alignment' => ['vertical' => 'center'],
        'borders' => ['allBorders' => ['borderStyle' => 'thin', 'color' => ['rgb' => $blueDark]]],
    ]);
    $sheet->getStyle("E{$headerRow}:G{$headerRow}")->getAlignment()->setHorizontal('right');
    $sheet->getRowDimension($headerRow)->setRowHeight(24);

    $row = $headerRow + 1;
    $sheet->fromArray([
        (string) ($period['from'] ?? ''),
        'OPENING',
        'Opening Balance',
        'Balance brought forward before this statement period',
        null,
        null,
        (float) ($summary['opening_balance'] ?? 0),
    ], null, "A{$row}", true);
    $sheet->getStyle("A{$row}:G{$row}")->applyFromArray([
        'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => $lightBlue]],
        'font' => ['italic' => true, 'color' => ['rgb' => $navy]],
        'borders' => ['bottom' => ['borderStyle' => 'hair', 'color' => ['rgb' => $border]]],
    ]);
    $sheet->getStyle("G{$row}")->getFont()->setBold(true);
    $sheet->getStyle("E{$row}:F{$row}")->getNumberFormat()->setFormatCode(clientStatementExcelTransactionMoneyFormat($currency));
    $sheet->getStyle("G{$row}")->getNumberFormat()->setFormatCode(clientStatementExcelMoneyFormat($currency));
    $row++;

    foreach ($ledger as $entry) {
        $description = (string) ($entry['description'] ?? '');
        if (trim((string) ($entry['notes'] ?? '')) !== '') {
            $description .= ($description !== '' ? "\n" : '') . (string) $entry['notes'];
        }
        $sheet->fromArray([
            (string) ($entry['transaction_date'] ?? ''),
            (string) ($entry['reference'] ?? ''),
            (string) ($entry['type_label'] ?? 'Transaction'),
            $description,
            (float) ($entry['debit'] ?? 0),
            (float) ($entry['credit'] ?? 0),
            (float) ($entry['balance'] ?? 0),
        ], null, "A{$row}", true);
        $sheet->getStyle("A{$row}:G{$row}")->applyFromArray([
            'font' => ['size' => 9, 'color' => ['rgb' => $navy]],
            'alignment' => ['vertical' => 'top'],
            'borders' => ['bottom' => ['borderStyle' => 'hair', 'color' => ['rgb' => $border]]],
        ]);
        $sheet->getStyle("D{$row}")->getAlignment()->setWrapText(true);
        $sheet->getStyle("E{$row}:F{$row}")->getNumberFormat()->setFormatCode(clientStatementExcelTransactionMoneyFormat($currency));
        $sheet->getStyle("G{$row}")->getNumberFormat()->setFormatCode(clientStatementExcelMoneyFormat($currency));
        $sheet->getStyle("E{$row}:G{$row}")->getAlignment()->setHorizontal('right');
        if (((float) ($entry['credit'] ?? 0)) > 0) {
            $sheet->getStyle("F{$row}")->getFont()->getColor()->setRGB($green);
        }
        if (((float) ($entry['debit'] ?? 0)) > 0) {
            $sheet->getStyle("E{$row}")->getFont()->getColor()->setRGB($red);
        }
        $row++;
    }

    $totalRow = $row;
    $sheet->mergeCells("A{$totalRow}:D{$totalRow}");
    $sheet->setCellValue("A{$totalRow}", 'PERIOD TOTALS / CLOSING BALANCE');
    $sheet->setCellValue("E{$totalRow}", (float) ($summary['total_debits'] ?? 0));
    $sheet->setCellValue("F{$totalRow}", (float) ($summary['total_credits'] ?? 0));
    $sheet->setCellValue("G{$totalRow}", (float) ($summary['closing_balance'] ?? 0));
    $sheet->getStyle("A{$totalRow}:G{$totalRow}")->applyFromArray([
        'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => $navy]],
        'font' => ['bold' => true, 'color' => ['rgb' => $white]],
        'borders' => ['allBorders' => ['borderStyle' => 'thin', 'color' => ['rgb' => $navy]]],
    ]);
    $sheet->getStyle("E{$totalRow}:G{$totalRow}")->getNumberFormat()->setFormatCode(clientStatementExcelMoneyFormat($currency));
    $sheet->getStyle("E{$totalRow}:G{$totalRow}")->getAlignment()->setHorizontal('right');
    $sheet->getRowDimension($totalRow)->setRowHeight(23);

    $footerRow = $totalRow + 2;
    $sheet->mergeCells("A{$footerRow}:G" . ($footerRow + 1));
    $footer = trim((string) ($company['legal_footer'] ?? ''));
    if ($footer === '') {
        $footer = 'This statement reflects valid receivable activity recorded in Otelex for the selected client, period and currency.';
    }
    $sheet->setCellValue("A{$footerRow}", $footer);
    $sheet->getStyle("A{$footerRow}:G" . ($footerRow + 1))->applyFromArray([
        'font' => ['italic' => true, 'size' => 8, 'color' => ['rgb' => $muted]],
        'alignment' => ['wrapText' => true, 'vertical' => 'top'],
    ]);

    $sheet->freezePane('A15');
    $sheet->setAutoFilter("A{$headerRow}:G" . max($headerRow, $totalRow - 1));
    $sheet->getPageSetup()
        ->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE)
        ->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4)
        ->setFitToWidth(1)
        ->setFitToHeight(0);
    $sheet->getPageMargins()->setTop(0.45)->setBottom(0.45)->setLeft(0.35)->setRight(0.35);
    $sheet->getHeaderFooter()->setOddFooter('&LGenerated by Otelex&CPage &P of &N&R' . date('d M Y H:i'));
    $sheet->getStyle('A1:G' . ($footerRow + 1))->getAlignment()->setVertical('center');

    $safeClient = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) ($client['company_name'] ?? 'Client'));
    $safeClient = trim((string) $safeClient, '_') ?: 'Client';
    $filename = sprintf(
        'Otelex_Client_Statement_%s_%s_%s_%s.xlsx',
        $safeClient,
        $currency,
        (string) ($period['from'] ?? date('Y-m-d')),
        (string) ($period['to'] ?? date('Y-m-d'))
    );

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: private, no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');

    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    $spreadsheet->disconnectWorksheets();
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        throw new Exception('Method Not Allowed', 405);
    }

    $user = authenticateUser();
    requireRole(
        $user,
        [ROLE_SUPER_ADMIN, ROLE_ADMIN, ROLE_ACCOUNTING],
        'Only Admins and Accounting users can access client statements.'
    );

    $clientId = isset($_GET['client_id']) && is_numeric($_GET['client_id'])
        ? (int) $_GET['client_id']
        : 0;

    if ($clientId < 1) {
        throw new Exception("A valid 'client_id' is required.", 422);
    }

    $from = clientStatementDate((string) ($_GET['from'] ?? ''), 'From date', date('Y-01-01'));
    $to = clientStatementDate((string) ($_GET['to'] ?? ''), 'To date', date('Y-m-d'));

    if ($from > $to) {
        throw new Exception('From date cannot be after To date.', 422);
    }

    $clientStmt = $conn->prepare(
        'SELECT id, company_name, email, phone, billing_address, city, state, country,
                tax_id, currency, payment_terms, is_active
         FROM clients
         WHERE id = ?
         LIMIT 1'
    );
    if (!$clientStmt) {
        throw new RuntimeException('Unable to prepare client lookup.');
    }
    $clientStmt->bind_param('i', $clientId);
    $clientStmt->execute();
    $client = $clientStmt->get_result()->fetch_assoc();
    $clientStmt->close();

    if (!$client) {
        throw new Exception('Client not found.', 404);
    }

    $requestedCurrency = strtoupper(trim((string) ($_GET['currency'] ?? '')));
    if ($requestedCurrency !== '' && !in_array($requestedCurrency, ['NGN', 'USD'], true)) {
        throw new Exception('Currency must be NGN or USD.', 422);
    }

    $currency = $requestedCurrency !== ''
        ? $requestedCurrency
        : strtoupper((string) ($client['currency'] ?? 'NGN'));

    if (!in_array($currency, ['NGN', 'USD'], true)) {
        $currency = 'NGN';
    }

    // Currency history is returned so the frontend can avoid ever combining
    // unlike currencies into one balance. Payments are included independently
    // because a partially paid invoice may later be cancelled; the cancelled
    // invoice no longer contributes a debit, but the money received remains a
    // real client credit until it is otherwise resolved.
    $currencyStmt = $conn->prepare(
        "SELECT DISTINCT currency
         FROM (
             SELECT i.currency
             FROM invoices i
             WHERE i.client_id = ?
               AND i.status NOT IN ('draft', 'cancelled', 'reversed')

             UNION

             SELECT i.currency
             FROM payments p
             INNER JOIN invoices i ON i.id = p.invoice_id
             WHERE i.client_id = ?
         ) statement_currencies
         ORDER BY currency ASC"
    );
    $currencyStmt->bind_param('ii', $clientId, $clientId);
    $currencyStmt->execute();
    $currencyRows = $currencyStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $currencyStmt->close();

    $availableCurrencies = [];
    $preferredCurrency = strtoupper((string) ($client['currency'] ?? 'NGN'));
    if (in_array($preferredCurrency, ['NGN', 'USD'], true)) {
        $availableCurrencies[] = $preferredCurrency;
    }
    foreach ($currencyRows as $row) {
        $item = strtoupper((string) ($row['currency'] ?? ''));
        if (in_array($item, ['NGN', 'USD'], true) && !in_array($item, $availableCurrencies, true)) {
            $availableCurrencies[] = $item;
        }
    }
    if (!in_array($currency, $availableCurrencies, true)) {
        $availableCurrencies[] = $currency;
    }

    // End-exclusive timestamp lets indexed timestamp columns be queried without DATE().
    $fromDateTime = $from . ' 00:00:00';
    $toExclusive = (new DateTime($to))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';

    // ---------------------------------------------------------------------
    // Opening balance: every valid transaction strictly before the period.
    // ---------------------------------------------------------------------
    $openingInvoices = clientStatementScalar(
        $conn,
        "SELECT COALESCE(SUM(i.total_amount), 0) AS total
         FROM invoices i
         WHERE i.client_id = ? AND i.currency = ? AND i.issue_date < ?
           AND i.status NOT IN ('draft', 'cancelled', 'reversed')",
        'iss',
        [$clientId, $currency, $from]
    );

    $openingPayments = clientStatementScalar(
        $conn,
        "SELECT COALESCE(SUM(p.amount), 0) AS total
         FROM payments p
         INNER JOIN invoices i ON i.id = p.invoice_id
         WHERE i.client_id = ? AND i.currency = ? AND p.payment_date < ?",
        'iss',
        [$clientId, $currency, $from]
    );

    $openingCredits = clientStatementScalar(
        $conn,
        "SELECT COALESCE(SUM(cn.amount), 0) AS total
         FROM credit_notes cn
         INNER JOIN invoices i ON i.id = cn.invoice_id
         WHERE i.client_id = ? AND i.currency = ? AND cn.issued_at < ?
           AND cn.status = 'issued'
           AND i.status NOT IN ('draft', 'cancelled', 'reversed')",
        'iss',
        [$clientId, $currency, $fromDateTime]
    );

    $openingRefunds = clientStatementScalar(
        $conn,
        "SELECT COALESCE(SUM(r.amount), 0) AS total
         FROM refunds r
         INNER JOIN invoices i ON i.id = r.invoice_id
         WHERE i.client_id = ? AND i.currency = ? AND r.refund_date < ?
           AND r.status = 'processed'
           AND i.status NOT IN ('draft', 'cancelled', 'reversed')",
        'iss',
        [$clientId, $currency, $from]
    );

    $openingBalance = clientStatementMoney(
        $openingInvoices + $openingRefunds - $openingPayments - $openingCredits
    );

    // ---------------------------------------------------------------------
    // Period activity.
    // ---------------------------------------------------------------------
    $invoiceStmt = $conn->prepare(
        "SELECT i.id, i.invoice_number, i.issue_date, i.due_date, i.total_amount,
                i.status, i.payment_terms
         FROM invoices i
         WHERE i.client_id = ? AND i.currency = ?
           AND i.issue_date BETWEEN ? AND ?
           AND i.status NOT IN ('draft', 'cancelled', 'reversed')
         ORDER BY i.issue_date ASC, i.id ASC"
    );
    $invoiceStmt->bind_param('isss', $clientId, $currency, $from, $to);
    $invoiceStmt->execute();
    $invoiceRows = $invoiceStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $invoiceStmt->close();

    $paymentStmt = $conn->prepare(
        "SELECT p.id, p.invoice_id, p.amount, p.payment_date, p.payment_method,
                p.reference, p.notes, i.invoice_number, i.status AS invoice_status
         FROM payments p
         INNER JOIN invoices i ON i.id = p.invoice_id
         WHERE i.client_id = ? AND i.currency = ?
           AND p.payment_date BETWEEN ? AND ?
         ORDER BY p.payment_date ASC, p.id ASC"
    );
    $paymentStmt->bind_param('isss', $clientId, $currency, $from, $to);
    $paymentStmt->execute();
    $paymentRows = $paymentStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $paymentStmt->close();

    $creditStmt = $conn->prepare(
        "SELECT cn.id, cn.credit_note_number, cn.invoice_id, cn.amount, cn.issued_at,
                cn.reason, i.invoice_number
         FROM credit_notes cn
         INNER JOIN invoices i ON i.id = cn.invoice_id
         WHERE i.client_id = ? AND i.currency = ?
           AND cn.issued_at >= ? AND cn.issued_at < ?
           AND cn.status = 'issued'
           AND i.status NOT IN ('draft', 'cancelled', 'reversed')
         ORDER BY cn.issued_at ASC, cn.id ASC"
    );
    $creditStmt->bind_param('isss', $clientId, $currency, $fromDateTime, $toExclusive);
    $creditStmt->execute();
    $creditRows = $creditStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $creditStmt->close();

    $refundStmt = $conn->prepare(
        "SELECT r.id, r.refund_number, r.credit_note_id, r.invoice_id, r.amount,
                r.refund_date, r.payment_method, r.reference, r.notes,
                i.invoice_number, cn.credit_note_number
         FROM refunds r
         INNER JOIN invoices i ON i.id = r.invoice_id
         INNER JOIN credit_notes cn ON cn.id = r.credit_note_id
         WHERE i.client_id = ? AND i.currency = ?
           AND r.refund_date BETWEEN ? AND ?
           AND r.status = 'processed'
           AND i.status NOT IN ('draft', 'cancelled', 'reversed')
         ORDER BY r.refund_date ASC, r.id ASC"
    );
    $refundStmt->bind_param('isss', $clientId, $currency, $from, $to);
    $refundStmt->execute();
    $refundRows = $refundStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $refundStmt->close();

    $entries = [];

    foreach ($invoiceRows as $row) {
        $entries[] = [
            'sort_date' => $row['issue_date'],
            'sort_order' => 10,
            'sort_id' => (int) $row['id'],
            'id' => (int) $row['id'],
            'invoice_id' => (int) $row['id'],
            'transaction_date' => $row['issue_date'],
            'transaction_type' => 'invoice',
            'type_label' => 'Invoice',
            'reference' => $row['invoice_number'],
            'related_reference' => null,
            'description' => 'Invoice issued',
            'due_date' => $row['due_date'],
            'status' => $row['status'],
            'payment_method' => null,
            'external_reference' => null,
            'notes' => null,
            'debit' => clientStatementMoney((float) $row['total_amount']),
            'credit' => 0.00,
        ];
    }

    foreach ($creditRows as $row) {
        $entries[] = [
            'sort_date' => substr((string) $row['issued_at'], 0, 10),
            'sort_order' => 20,
            'sort_id' => (int) $row['id'],
            'id' => (int) $row['id'],
            'invoice_id' => (int) $row['invoice_id'],
            'transaction_date' => substr((string) $row['issued_at'], 0, 10),
            'transaction_type' => 'credit_note',
            'type_label' => 'Credit Note',
            'reference' => $row['credit_note_number'],
            'related_reference' => $row['invoice_number'],
            'description' => 'Credit note against ' . $row['invoice_number'],
            'due_date' => null,
            'status' => 'issued',
            'payment_method' => null,
            'external_reference' => null,
            'notes' => $row['reason'],
            'debit' => 0.00,
            'credit' => clientStatementMoney((float) $row['amount']),
        ];
    }

    foreach ($paymentRows as $row) {
        $methodLabel = clientStatementPaymentMethodLabel($row['payment_method'] ?? null);
        $invoiceStatus = strtolower((string) ($row['invoice_status'] ?? ''));
        $invoiceContext = $invoiceStatus === 'cancelled'
            ? ' (invoice later cancelled)'
            : ($invoiceStatus === 'reversed' ? ' (invoice later reversed)' : '');
        $entries[] = [
            'sort_date' => $row['payment_date'],
            'sort_order' => 30,
            'sort_id' => (int) $row['id'],
            'id' => (int) $row['id'],
            'invoice_id' => (int) $row['invoice_id'],
            'transaction_date' => $row['payment_date'],
            'transaction_type' => 'payment',
            'type_label' => 'Payment',
            'reference' => trim((string) ($row['reference'] ?? '')) !== ''
                ? (string) $row['reference']
                : 'PAY-' . (int) $row['id'],
            'related_reference' => $row['invoice_number'],
            'description' => $methodLabel . ' payment received for ' . $row['invoice_number'] . $invoiceContext,
            'due_date' => null,
            'status' => 'recorded',
            'payment_method' => $row['payment_method'],
            'external_reference' => $row['reference'],
            'notes' => $row['notes'],
            'debit' => 0.00,
            'credit' => clientStatementMoney((float) $row['amount']),
        ];
    }

    foreach ($refundRows as $row) {
        $methodLabel = clientStatementPaymentMethodLabel($row['payment_method'] ?? null);
        $entries[] = [
            'sort_date' => $row['refund_date'],
            'sort_order' => 40,
            'sort_id' => (int) $row['id'],
            'id' => (int) $row['id'],
            'invoice_id' => (int) $row['invoice_id'],
            'transaction_date' => $row['refund_date'],
            'transaction_type' => 'refund',
            'type_label' => 'Refund',
            'reference' => $row['refund_number'],
            'related_reference' => $row['credit_note_number'] . ' / ' . $row['invoice_number'],
            'description' => $methodLabel . ' refund processed against ' . $row['credit_note_number'],
            'due_date' => null,
            'status' => 'processed',
            'payment_method' => $row['payment_method'],
            'external_reference' => $row['reference'],
            'notes' => $row['notes'],
            'debit' => clientStatementMoney((float) $row['amount']),
            'credit' => 0.00,
        ];
    }

    usort($entries, static function (array $a, array $b): int {
        $dateCompare = strcmp((string) $a['sort_date'], (string) $b['sort_date']);
        if ($dateCompare !== 0) {
            return $dateCompare;
        }

        $orderCompare = ((int) $a['sort_order']) <=> ((int) $b['sort_order']);
        return $orderCompare !== 0 ? $orderCompare : ((int) $a['sort_id'] <=> (int) $b['sort_id']);
    });

    $runningBalance = $openingBalance;
    $ledger = [];
    $totalDebits = 0.00;
    $totalCredits = 0.00;
    $breakdown = [
        'invoices' => ['count' => 0, 'amount' => 0.00],
        'payments' => ['count' => 0, 'amount' => 0.00],
        'credit_notes' => ['count' => 0, 'amount' => 0.00],
        'refunds' => ['count' => 0, 'amount' => 0.00],
    ];

    foreach ($entries as $entry) {
        $debit = clientStatementMoney((float) $entry['debit']);
        $credit = clientStatementMoney((float) $entry['credit']);
        $runningBalance = clientStatementMoney($runningBalance + $debit - $credit);
        $totalDebits = clientStatementMoney($totalDebits + $debit);
        $totalCredits = clientStatementMoney($totalCredits + $credit);

        switch ($entry['transaction_type']) {
            case 'invoice':
                $breakdown['invoices']['count']++;
                $breakdown['invoices']['amount'] = clientStatementMoney($breakdown['invoices']['amount'] + $debit);
                break;
            case 'payment':
                $breakdown['payments']['count']++;
                $breakdown['payments']['amount'] = clientStatementMoney($breakdown['payments']['amount'] + $credit);
                break;
            case 'credit_note':
                $breakdown['credit_notes']['count']++;
                $breakdown['credit_notes']['amount'] = clientStatementMoney($breakdown['credit_notes']['amount'] + $credit);
                break;
            case 'refund':
                $breakdown['refunds']['count']++;
                $breakdown['refunds']['amount'] = clientStatementMoney($breakdown['refunds']['amount'] + $debit);
                break;
        }

        unset($entry['sort_date'], $entry['sort_order'], $entry['sort_id']);
        $entry['balance'] = $runningBalance;
        // Backward-compatible alias for the earlier report response.
        $entry['running_balance'] = $runningBalance;
        $ledger[] = $entry;
    }

    $closingBalance = clientStatementMoney($runningBalance);
    $netMovement = clientStatementMoney($totalDebits - $totalCredits);
    $expectedClosingBalance = clientStatementMoney($openingBalance + $totalDebits - $totalCredits);

    // Final integrity guard: screen, Excel and PDF all consume this same
    // statement payload, so never return a ledger whose closing balance does
    // not reconcile to opening balance plus period movement.
    if (abs($closingBalance - $expectedClosingBalance) > 0.01) {
        throw new RuntimeException('Client statement balance reconciliation failed.');
    }

    $company = null;
    $companyResult = $conn->query(
        'SELECT company_name, address, city, state, country, phone, email, website,
                logo_path, vat_number, legal_footer
         FROM company_settings
         LIMIT 1'
    );
    if ($companyResult && ($companyRow = $companyResult->fetch_assoc())) {
        $company = [
            'company_name' => $companyRow['company_name'],
            'address' => $companyRow['address'],
            'city' => $companyRow['city'],
            'state' => $companyRow['state'],
            'country' => $companyRow['country'],
            'phone' => $companyRow['phone'],
            'email' => $companyRow['email'],
            'website' => $companyRow['website'],
            'logo_path' => normalizeStoredUploadUrl($companyRow['logo_path'] ?? null),
            'vat_number' => $companyRow['vat_number'],
            'legal_footer' => $companyRow['legal_footer'],
        ];
    }

    $statementData = [
        'company' => $company,
        'client' => [
            'id' => (int) $client['id'],
            'company_name' => $client['company_name'],
            'email' => $client['email'],
            'phone' => $client['phone'],
            'address' => $client['billing_address'],
            'city' => $client['city'],
            'state' => $client['state'],
            'country' => $client['country'],
            'tax_id' => $client['tax_id'],
            'preferred_currency' => $preferredCurrency,
            'payment_terms' => $client['payment_terms'],
            'is_active' => (bool) $client['is_active'],
        ],
        'period' => [
            'from' => $from,
            'to' => $to,
            'currency' => $currency,
        ],
        'available_currencies' => $availableCurrencies,
        'has_multiple_currencies' => count($availableCurrencies) > 1,
        'summary' => [
            'opening_balance' => $openingBalance,
            'total_debits' => $totalDebits,
            'total_credits' => $totalCredits,
            'net_movement' => $netMovement,
            'closing_balance' => $closingBalance,
            'balance_position' => clientStatementBalancePosition($closingBalance),
            'transaction_count' => count($ledger),
            // Detailed breakdown retained for screen/export summaries.
            'total_invoiced' => $breakdown['invoices']['amount'],
            'total_paid' => $breakdown['payments']['amount'],
            'total_credit_notes' => $breakdown['credit_notes']['amount'],
            'total_refunds' => $breakdown['refunds']['amount'],
        ],
        'breakdown' => $breakdown,
        'ledger' => $ledger,
    ];

    if (strtolower(trim((string) ($_GET['format'] ?? ''))) === 'xlsx') {
        clientStatementExportExcel($statementData);
    }

    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 'success',
        'message' => 'Client statement fetched successfully.',
        'data' => $statementData,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('Client Statement Report Error: ' . $e->getMessage());
    $code = (int) $e->getCode();
    $code = ($code >= 400 && $code < 500) ? $code : 500;

    http_response_code($code);
    echo json_encode([
        'status' => 'failed',
        'message' => $code === 500
            ? 'Client statement could not be generated right now.'
            : $e->getMessage(),
    ]);
}
