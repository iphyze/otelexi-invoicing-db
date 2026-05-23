<?php
// utils/emailTemplates.php
// All HTML email templates in one place.
// Each function returns a complete HTML string ready to pass to sendMail().

/**
 * Shared branded email wrapper.
 * Wraps any content in the Otelex header/footer layout.
 */
function emailWrapper(string $content, string $companyName = 'Otelex'): string
{
    $year = date('Y');
    return <<<HTML
    <!DOCTYPE html>
    <html lang="en">
    <head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1.0">
      <title>{$companyName}</title>
      <style>
        body { margin:0; padding:0; background:#f0f4ff; font-family:'Segoe UI',Arial,sans-serif; color:#1e293b; }
        .wrap { max-width:600px; margin:32px auto; background:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 4px 24px rgba(0,0,0,0.08); }
        .header { background:linear-gradient(135deg,#1a56db,#1e3a8a); padding:28px 36px; }
        .header h1 { margin:0; color:#fff; font-size:22px; font-weight:800; letter-spacing:-0.4px; }
        .header p  { margin:4px 0 0; color:rgba(255,255,255,0.7); font-size:13px; }
        .body   { padding:36px; }
        .body p { font-size:14px; line-height:1.7; color:#374151; margin:0 0 14px; }
        .body h2 { font-size:18px; font-weight:700; color:#1e293b; margin:0 0 16px; }
        .btn  { display:inline-block; padding:13px 28px; background:linear-gradient(135deg,#1a56db,#1e3a8a);
                color:#fff; text-decoration:none; border-radius:10px; font-weight:700; font-size:14px;
                letter-spacing:0.2px; margin:8px 0; }
        .info-box { background:#f8faff; border:1px solid #e2e8f0; border-radius:10px; padding:18px 20px; margin:20px 0; }
        .info-box p { margin:0 0 8px; font-size:13.5px; }
        .info-box p:last-child { margin:0; }
        .info-box strong { color:#1a56db; }
        .divider { height:1px; background:#f1f5f9; margin:24px 0; }
        .footer { background:#f8faff; border-top:1px solid #e2e8f0; padding:20px 36px; text-align:center; }
        .footer p { font-size:12px; color:#94a3b8; margin:0; line-height:1.6; }
        .amount { font-size:28px; font-weight:800; color:#1a56db; letter-spacing:-0.5px; }
        .status-badge { display:inline-block; padding:4px 12px; border-radius:20px; font-size:12px; font-weight:700; }
        .status-paid    { background:rgba(16,185,129,0.1); color:#10b981; }
        .status-pending { background:rgba(245,158,11,0.1); color:#f59e0b; }
        .status-overdue { background:rgba(239,68,68,0.1);  color:#ef4444; }
        table.items { width:100%; border-collapse:collapse; margin:16px 0; font-size:13px; }
        table.items th { background:#f1f5f9; padding:10px 12px; text-align:left; font-weight:700; color:#64748b; font-size:11px; text-transform:uppercase; letter-spacing:0.5px; }
        table.items td { padding:11px 12px; border-bottom:1px solid #f1f5f9; }
        table.items tr:last-child td { border-bottom:none; }
        .total-row td { font-weight:700; color:#1e293b; border-top:2px solid #e2e8f0 !important; padding-top:14px !important; }
      </style>
    </head>
    <body>
      <div class="wrap">
        <div class="header">
          <h1>{$companyName}</h1>
          <p>Invoicing &amp; Supply Management</p>
        </div>
        <div class="body">
          {$content}
        </div>
        <div class="footer">
          <p>&copy; {$year} {$companyName}. All rights reserved.<br>
          This is an automated email — please do not reply directly to this message.</p>
        </div>
      </div>
    </body>
    </html>
    HTML;
}

// ── PASSWORD RESET ─────────────────────────────────────────────────

/**
 * Forgot password email template.
 */
function emailForgotPassword(string $userName, string $resetUrl, string $companyName = 'Otelex'): string
{
    $content = <<<HTML
    <h2>Reset Your Password</h2>
    <p>Hi <strong>{$userName}</strong>,</p>
    <p>We received a request to reset the password for your {$companyName} account. 
       Click the button below to choose a new password. This link expires in <strong>30 minutes</strong>.</p>
    <p style="text-align:center; margin:28px 0;">
      <a class="btn" href="{$resetUrl}" style="color: #fff">Reset Password</a>
    </p>
    <div class="info-box">
      <p>If the button doesn't work, copy and paste this link into your browser:</p>
      <p style="word-break:break-all; color:#1a56db; font-size:12.5px;">{$resetUrl}</p>
    </div>
    <div class="divider"></div>
    <p style="font-size:13px; color:#94a3b8;">
      If you didn't request a password reset, you can safely ignore this email. 
      Your password will remain unchanged.
    </p>
    HTML;

    return emailWrapper($content, $companyName);
}

// ── INVOICE ────────────────────────────────────────────────────────

/**
 * Invoice delivery email template.
 */
function emailInvoiceDelivery(array $data, string $companyName = 'Otelex'): string
{
    $invoiceNumber = htmlspecialchars($data['invoice_number']);
    $clientName    = htmlspecialchars($data['client_name']);
    $totalAmount   = htmlspecialchars($data['total_amount']);
    $balanceDue    = htmlspecialchars($data['balance_due']);
    $dueDate       = htmlspecialchars($data['due_date']);
    $issueDate     = htmlspecialchars($data['issue_date']);
    $paymentTerms  = htmlspecialchars($data['payment_terms']);
    $notes         = !empty($data['notes']) ? '<p><em>' . htmlspecialchars($data['notes']) . '</em></p>' : '';

    // Bank details from company settings
    $bankName    = htmlspecialchars($data['bank_name']    ?? '');
    $accountName = htmlspecialchars($data['account_name'] ?? '');
    $accountNo   = htmlspecialchars($data['account_number'] ?? '');

    $bankSection = '';
    if ($bankName && $accountNo) {
        $bankSection = <<<HTML
        <div class="info-box">
          <p><strong>Payment Details</strong></p>
          <p>Bank: <strong>{$bankName}</strong></p>
          <p>Account Name: <strong>{$accountName}</strong></p>
          <p>Account Number: <strong>{$accountNo}</strong></p>
        </div>
        HTML;
    }

    $content = <<<HTML
    <h2>Invoice {$invoiceNumber}</h2>
    <p>Dear <strong>{$clientName}</strong>,</p>
    <p>Please find attached your invoice <strong>{$invoiceNumber}</strong>. 
       Below is a summary of the amount due.</p>

    <div class="info-box">
      <p>Invoice Number: <strong>{$invoiceNumber}</strong></p>
      <p>Issue Date: <strong>{$issueDate}</strong></p>
      <p>Due Date: <strong>{$dueDate}</strong></p>
      <p>Payment Terms: <strong>{$paymentTerms}</strong></p>
      <p>Total Amount: <strong class="amount">{$totalAmount}</strong></p>
      <p>Balance Due: <strong style="color:#ef4444; font-size:18px;">{$balanceDue}</strong></p>
    </div>

    {$bankSection}
    {$notes}

    <p>The full invoice is attached as a PDF to this email. 
       Please ensure payment is made by <strong>{$dueDate}</strong>.</p>
    <p>For any queries, please do not hesitate to contact us.</p>

    <div class="divider"></div>
    <p style="font-size:12px; color:#94a3b8;">
      {$data['footer_text']}
    </p>
    HTML;

    return emailWrapper($content, $companyName);
}

// ── OVERDUE REMINDER ───────────────────────────────────────────────

/**
 * Overdue invoice reminder email template.
 */
function emailOverdueReminder(array $data, string $companyName = 'Otelex'): string
{
    $invoiceNumber  = htmlspecialchars((string) $data['invoice_number'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $clientName     = htmlspecialchars((string) $data['client_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $balanceDue     = htmlspecialchars((string) $data['balance_due'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $dueDate        = htmlspecialchars((string) $data['due_date'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $daysOverdue    = (int) $data['days_overdue'];
    $reminderCount  = (int) $data['reminder_count'];
    $bankName       = htmlspecialchars((string) ($data['bank_name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $accountName    = htmlspecialchars((string) ($data['account_name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $accountNumber  = htmlspecialchars((string) ($data['account_number'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $heading = $reminderCount >= 3 ? 'Final Payment Reminder' : 'Payment Reminder';

    $urgency = $reminderCount >= 3 || $daysOverdue > 30
        ? '<p style="color:#ef4444; font-weight:700;">This invoice is significantly overdue. Please arrange payment immediately or contact us regarding payment status.</p>'
        : '<p>We kindly ask you to arrange payment at your earliest convenience.</p>';

    $paymentDetails = '';
    if ($bankName !== '' && $accountNumber !== '') {
        $paymentDetails = <<<HTML
        <div class="info-box">
          <p><strong>Payment Details</strong></p>
          <p>Bank: <strong>{$bankName}</strong></p>
          <p>Account Name: <strong>{$accountName}</strong></p>
          <p>Account Number: <strong>{$accountNumber}</strong></p>
        </div>
        HTML;
    }

    $content = <<<HTML
    <h2>{$heading} — {$invoiceNumber}</h2>
    <p>Dear <strong>{$clientName}</strong>,</p>
    <p>This is reminder #{$reminderCount} regarding invoice <strong>{$invoiceNumber}</strong>,
       which was due on <strong>{$dueDate}</strong> and is now <strong>{$daysOverdue} day(s) overdue</strong>.</p>

    <div class="info-box">
      <p>Invoice: <strong>{$invoiceNumber}</strong></p>
      <p>Original Due Date: <strong>{$dueDate}</strong></p>
      <p>Days Overdue: <strong style="color:#ef4444;">{$daysOverdue} days</strong></p>
      <p>Outstanding Balance: <strong class="amount">{$balanceDue}</strong></p>
    </div>

    {$paymentDetails}
    {$urgency}

    <p>If you have already made this payment, please disregard this notice and forward
       your payment reference to us so we can update our records.</p>
    HTML;

    return emailWrapper($content, $companyName);
}

// ── QUOTATION ──────────────────────────────────────────────────────

/**
 * Quotation delivery email template.
 */
function emailQuotationDelivery(array $data, string $companyName = 'Otelex'): string
{
    $quotationNumber = htmlspecialchars($data['quotation_number']);
    $clientName      = htmlspecialchars($data['client_name']);
    $totalAmount     = htmlspecialchars($data['total_amount']);
    $expiryDate      = htmlspecialchars($data['expiry_date']);
    $issueDate       = htmlspecialchars($data['issue_date']);
    $notes           = !empty($data['notes']) ? '<p><em>' . htmlspecialchars($data['notes']) . '</em></p>' : '';

    $content = <<<HTML
    <h2>Quotation {$quotationNumber}</h2>
    <p>Dear <strong>{$clientName}</strong>,</p>
    <p>Thank you for your interest. Please find attached our quotation 
       <strong>{$quotationNumber}</strong> for your review.</p>

    <div class="info-box">
      <p>Quotation Number: <strong>{$quotationNumber}</strong></p>
      <p>Issue Date: <strong>{$issueDate}</strong></p>
      <p>Valid Until: <strong>{$expiryDate}</strong></p>
      <p>Total Value: <strong class="amount">{$totalAmount}</strong></p>
    </div>

    {$notes}

    <p>This quotation is valid until <strong>{$expiryDate}</strong>. 
       To proceed, please confirm your acceptance and we will issue a proforma invoice.</p>
    <p>The full quotation details are attached as a PDF to this email.</p>
    HTML;

    return emailWrapper($content, $companyName);
}

// ── PROFORMA ───────────────────────────────────────────────────────

/**
 * Proforma invoice delivery email template.
 */
function emailProformaDelivery(array $data, string $companyName = 'Otelex'): string
{
    $proformaNumber = htmlspecialchars($data['proforma_number']);
    $clientName     = htmlspecialchars($data['client_name']);
    $totalAmount    = htmlspecialchars($data['total_amount']);
    $expiryDate     = htmlspecialchars($data['expiry_date'] ?? 'N/A');
    $issueDate      = htmlspecialchars($data['issue_date']);
    $notes          = !empty($data['notes']) ? '<p><em>' . htmlspecialchars($data['notes']) . '</em></p>' : '';

    $bankName    = htmlspecialchars($data['bank_name']     ?? '');
    $accountName = htmlspecialchars($data['account_name']  ?? '');
    $accountNo   = htmlspecialchars($data['account_number'] ?? '');

    $bankSection = '';
    if ($bankName && $accountNo) {
        $bankSection = <<<HTML
        <div class="info-box">
          <p><strong>Payment Details</strong></p>
          <p>Bank: <strong>{$bankName}</strong></p>
          <p>Account Name: <strong>{$accountName}</strong></p>
          <p>Account Number: <strong>{$accountNo}</strong></p>
        </div>
        HTML;
    }

    $content = <<<HTML
    <h2>Proforma Invoice {$proformaNumber}</h2>
    <p>Dear <strong>{$clientName}</strong>,</p>
    <p>Please find attached our proforma invoice <strong>{$proformaNumber}</strong>. 
       Kindly review and confirm your approval so we can proceed with the final invoice.</p>

    <div class="info-box">
      <p>Proforma Number: <strong>{$proformaNumber}</strong></p>
      <p>Issue Date: <strong>{$issueDate}</strong></p>
      <p>Valid Until: <strong>{$expiryDate}</strong></p>
      <p>Total Amount: <strong class="amount">{$totalAmount}</strong></p>
    </div>

    {$bankSection}
    {$notes}

    <p>Please reply to this email or contact us to confirm your approval of this proforma.</p>
    HTML;

    return emailWrapper($content, $companyName);
}

// ── PASSWORD CHANGED CONFIRMATION ──────────────────────────────────

/**
 * Confirmation email after successful password reset.
 */
function emailPasswordChanged(string $userName, string $companyName = 'Otelex'): string
{
    $content = <<<HTML
    <h2>Password Changed Successfully</h2>
    <p>Hi <strong>{$userName}</strong>,</p>
    <p>Your {$companyName} account password was successfully changed.</p>
    <p>If you did not make this change, please contact your administrator immediately.</p>
    <div class="info-box">
      <p style="margin:0; font-size:13px; color:#64748b;">
        This change was made on <strong>{$companyName}</strong>. 
        If you believe this is unauthorised activity, please contact support right away.
      </p>
    </div>
    HTML;

    return emailWrapper($content, $companyName);
}

// ── PAYMENT RECEIPT ───────────────────────────────────────────────

/**
 * Payment receipt delivery email template.
 */
function emailPaymentReceipt(array $data, string $companyName = 'Otelex'): string
{
    $receiptNumber = htmlspecialchars((string) $data['receipt_number'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $invoiceNumber = htmlspecialchars((string) $data['invoice_number'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $clientName = htmlspecialchars((string) $data['client_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $amountReceived = htmlspecialchars((string) $data['amount_received'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $balanceAfterPayment = htmlspecialchars((string) $data['balance_after_payment'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $paymentDate = htmlspecialchars((string) $data['payment_date'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $paymentMethod = htmlspecialchars((string) $data['payment_method'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $paymentReference = htmlspecialchars((string) ($data['payment_reference'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $referenceLine = $paymentReference !== ''
        ? "<p>Payment Reference: <strong>{$paymentReference}</strong></p>"
        : '';

    $content = <<<HTML
    <h2>Payment Receipt {$receiptNumber}</h2>
    <p>Dear <strong>{$clientName}</strong>,</p>
    <p>Thank you for your payment. Please find attached your official receipt for the payment received against invoice <strong>{$invoiceNumber}</strong>.</p>

    <div class="info-box">
      <p>Receipt Number: <strong>{$receiptNumber}</strong></p>
      <p>Invoice Number: <strong>{$invoiceNumber}</strong></p>
      <p>Payment Date: <strong>{$paymentDate}</strong></p>
      <p>Payment Method: <strong>{$paymentMethod}</strong></p>
      {$referenceLine}
      <p>Amount Received: <strong class="amount">{$amountReceived}</strong></p>
      <p>Remaining Invoice Balance: <strong>{$balanceAfterPayment}</strong></p>
    </div>

    <p>Your payment receipt is attached as a PDF for your records.</p>
    <p>Thank you for doing business with us.</p>
    HTML;

    return emailWrapper($content, $companyName);
}


// ── CREDIT NOTE ───────────────────────────────────────────────

/**
 * Credit note delivery email template.
 */
function emailCreditNote(array $data, string $companyName = 'Otelex'): string
{
    $creditNumber = htmlspecialchars((string) $data['credit_note_number'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $invoiceNumber = htmlspecialchars((string) $data['invoice_number'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $clientName = htmlspecialchars((string) $data['client_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $creditAmount = htmlspecialchars((string) $data['credit_amount'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $adjustedTotal = htmlspecialchars((string) $data['adjusted_total'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $reason = nl2br(htmlspecialchars((string) $data['reason'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    $issuedDate = htmlspecialchars((string) $data['issued_date'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $content = <<<HTML
    <h2>Credit Note {$creditNumber}</h2>
    <p>Dear <strong>{$clientName}</strong>,</p>
    <p>Please find attached the issued credit note relating to invoice <strong>{$invoiceNumber}</strong>.</p>

    <div class="info-box">
      <p>Credit Note Number: <strong>{$creditNumber}</strong></p>
      <p>Original Invoice: <strong>{$invoiceNumber}</strong></p>
      <p>Date Issued: <strong>{$issuedDate}</strong></p>
      <p>Credit Amount: <strong class="amount">{$creditAmount}</strong></p>
      <p>Adjusted Invoice Value: <strong>{$adjustedTotal}</strong></p>
      <p>Reason: <strong>{$reason}</strong></p>
    </div>

    <p>This credit note is attached as a PDF for your records. Please contact us if you require any clarification.</p>
    HTML;

    return emailWrapper($content, $companyName);
}
