-- 20261006_reset_commercial_transactional_data.sql
--
-- DESTRUCTIVE ONE-OFF COMMERCIAL DATA RESET.
-- Clears quotations, proformas, invoices and all records derived from them so
-- the commercial document workflow starts clean again.
--
-- PRESERVED:
-- - clients
-- - products/categories
-- - users/roles
-- - company/settings/master data
-- - stock adjustments that are not derived from invoices/credit notes
--
-- IMPORTANT:
-- 1. Take a full database backup before running this migration.
-- 2. This migration is safe to run even if the earlier invoice-only reset was
--    already executed; empty source tables simply produce no dependent rows.
-- 3. Visible quotation/proforma/invoice-derived document numbering is reset so
--    the next records start from sequence 001 for the current year.
-- 4. Temporary string keys use an explicit utf8mb4_unicode_ci collation so
--    the reset also works on MySQL 8 databases whose default collation is
--    utf8mb4_0900_ai_ci.

START TRANSACTION;

-- ---------------------------------------------------------------------------
-- Capture invoice-derived IDs/references before dependent records are removed.
-- ---------------------------------------------------------------------------
CREATE TEMPORARY TABLE tmp_reset_invoice_ids (
    id INT NOT NULL PRIMARY KEY
) ENGINE=MEMORY
AS
SELECT id FROM invoices;

CREATE TEMPORARY TABLE tmp_reset_credit_note_ids (
    id INT NOT NULL PRIMARY KEY
) ENGINE=MEMORY
AS
SELECT cn.id
FROM credit_notes cn
JOIN tmp_reset_invoice_ids i ON i.id = cn.invoice_id;

CREATE TEMPORARY TABLE tmp_reset_payment_link_refs (
    reference VARCHAR(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL PRIMARY KEY
) ENGINE=MEMORY DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
AS
SELECT DISTINCT CONVERT(pl.reference USING utf8mb4) COLLATE utf8mb4_unicode_ci AS reference
FROM payment_links pl
JOIN tmp_reset_invoice_ids i ON i.id = pl.invoice_id
WHERE pl.reference IS NOT NULL AND pl.reference <> '';

-- ---------------------------------------------------------------------------
-- Restore the NET inventory effect of invoice/credit-note activity before
-- removing the related stock movements.
--
--   movement_type = 'in'  => positive stock effect
--   movement_type = 'out' => negative stock effect
--
-- Subtracting the accumulated effect returns products to the position they
-- would have had if these commercial transactions had never existed.
-- ---------------------------------------------------------------------------
CREATE TEMPORARY TABLE tmp_reset_invoice_stock_delta (
    product_id INT NOT NULL PRIMARY KEY,
    net_effect DECIMAL(15,2) NOT NULL
) ENGINE=MEMORY
AS
SELECT
    sm.product_id,
    SUM(
        CASE
            WHEN sm.movement_type = 'in' THEN sm.quantity
            WHEN sm.movement_type = 'out' THEN -sm.quantity
            WHEN sm.movement_type = 'adjustment'
                 AND sm.balance_before IS NOT NULL
                 AND sm.balance_after IS NOT NULL
                THEN sm.balance_after - sm.balance_before
            ELSE 0
        END
    ) AS net_effect
FROM stock_movements sm
WHERE
    (
        sm.reference_type IN ('invoice', 'invoice_reversal')
        AND sm.reference_id IN (SELECT id FROM tmp_reset_invoice_ids)
    )
    OR
    (
        sm.reference_type = 'credit_note'
        AND sm.reference_id IN (SELECT id FROM tmp_reset_credit_note_ids)
    )
GROUP BY sm.product_id;

UPDATE products p
JOIN tmp_reset_invoice_stock_delta d ON d.product_id = p.id
SET p.stock_quantity = p.stock_quantity - d.net_effect;

DELETE FROM stock_movements
WHERE
    (
        reference_type IN ('invoice', 'invoice_reversal')
        AND reference_id IN (SELECT id FROM tmp_reset_invoice_ids)
    )
    OR
    (
        reference_type = 'credit_note'
        AND reference_id IN (SELECT id FROM tmp_reset_credit_note_ids)
    );

-- ---------------------------------------------------------------------------
-- Remove operational/history records tied to quotation/proforma/invoice flows.
-- ---------------------------------------------------------------------------
DELETE FROM payment_webhook_logs
WHERE CONVERT(reference USING utf8mb4) COLLATE utf8mb4_unicode_ci IN (
    SELECT reference COLLATE utf8mb4_unicode_ci
    FROM tmp_reset_payment_link_refs
);

-- Every supported document_email_logs document type belongs to the commercial
-- flow being reset, so the table can be cleared completely.
DELETE FROM document_email_logs;

DELETE FROM notifications
WHERE model_type IN (
        'Quotation',
        'Proforma',
        'ProformaInvoice',
        'Invoice',
        'Payment',
        'Receipt',
        'PaymentReceipt',
        'PaymentLink',
        'CreditNote',
        'Refund',
        'DeliveryNote',
        'CustomerPortalLink'
    )
   OR type LIKE 'quotation.%'
   OR type LIKE 'proforma.%'
   OR type LIKE 'invoice.%'
   OR type LIKE 'payment.%'
   OR type LIKE 'receipt.%'
   OR type LIKE 'credit_note.%'
   OR type LIKE 'refund.%'
   OR type LIKE 'delivery_note.%'
   OR type LIKE 'customer_portal.%';

DELETE FROM activity_log
WHERE model_type IN (
        'Quotation',
        'Proforma',
        'ProformaInvoice',
        'Invoice',
        'Payment',
        'Receipt',
        'PaymentReceipt',
        'PaymentLink',
        'CreditNote',
        'Refund',
        'DeliveryNote',
        'CustomerPortalLink'
    )
   OR action LIKE 'quotation.%'
   OR action LIKE 'proforma.%'
   OR action LIKE 'invoice.%'
   OR action LIKE 'payment.%'
   OR action LIKE 'receipt.%'
   OR action LIKE 'credit_note.%'
   OR action LIKE 'refund.%'
   OR action LIKE 'delivery_note.%'
   OR action LIKE 'customer_portal.%'
   OR action = 'document.maintenance_run';

-- Old document-check runs no longer describe the cleaned system.
DELETE FROM document_maintenance_runs;

-- ---------------------------------------------------------------------------
-- Remove invoice-derived relational records in FK-safe order.
-- ---------------------------------------------------------------------------
DELETE dni
FROM delivery_note_items dni
JOIN delivery_notes dn ON dn.id = dni.delivery_note_id
JOIN tmp_reset_invoice_ids i ON i.id = dn.invoice_id;

DELETE dn
FROM delivery_notes dn
JOIN tmp_reset_invoice_ids i ON i.id = dn.invoice_id;

DELETE r
FROM refunds r
JOIN tmp_reset_invoice_ids i ON i.id = r.invoice_id;

DELETE cn
FROM credit_notes cn
JOIN tmp_reset_invoice_ids i ON i.id = cn.invoice_id;

DELETE cpl
FROM customer_portal_links cpl
JOIN tmp_reset_invoice_ids i ON i.id = cpl.invoice_id;

-- Payment links can reference payments/receipts, so remove them first.
DELETE pl
FROM payment_links pl
JOIN tmp_reset_invoice_ids i ON i.id = pl.invoice_id;

DELETE pr
FROM payment_receipts pr
JOIN tmp_reset_invoice_ids i ON i.id = pr.invoice_id;

DELETE p
FROM payments p
JOIN tmp_reset_invoice_ids i ON i.id = p.invoice_id;

DELETE irl
FROM invoice_reminder_logs irl
JOIN tmp_reset_invoice_ids i ON i.id = irl.invoice_id;

DELETE ii
FROM invoice_items ii
JOIN tmp_reset_invoice_ids i ON i.id = ii.invoice_id;

DELETE inv
FROM invoices inv
JOIN tmp_reset_invoice_ids i ON i.id = inv.id;

-- ---------------------------------------------------------------------------
-- Remove all Proformas and Quotations.
-- Invoices are already gone, so their lineage FKs can no longer reference
-- these records. Item tables cascade, but are deleted explicitly for clarity.
-- ---------------------------------------------------------------------------
DELETE FROM proforma_items;
DELETE FROM proforma_invoices;

DELETE FROM quotation_items;
DELETE FROM quotations;

-- Reset all user-facing commercial document sequences while preserving stock
-- adjustment numbering/history.
DELETE FROM document_number_sequences
WHERE doc_type IN (
    'quotation',
    'proforma',
    'invoice',
    'receipt',
    'credit_note',
    'refund',
    'delivery_note'
);

COMMIT;

-- ---------------------------------------------------------------------------
-- Reset internal primary-key counters for tables that are now fully cleared.
-- These ALTER TABLE statements intentionally run after the transaction because
-- MySQL DDL performs implicit commits.
-- ---------------------------------------------------------------------------
ALTER TABLE quotations AUTO_INCREMENT = 1;
ALTER TABLE quotation_items AUTO_INCREMENT = 1;
ALTER TABLE proforma_invoices AUTO_INCREMENT = 1;
ALTER TABLE proforma_items AUTO_INCREMENT = 1;
ALTER TABLE invoices AUTO_INCREMENT = 1;
ALTER TABLE invoice_items AUTO_INCREMENT = 1;
ALTER TABLE invoice_reminder_logs AUTO_INCREMENT = 1;
ALTER TABLE payments AUTO_INCREMENT = 1;
ALTER TABLE payment_links AUTO_INCREMENT = 1;
ALTER TABLE payment_receipts AUTO_INCREMENT = 1;
ALTER TABLE credit_notes AUTO_INCREMENT = 1;
ALTER TABLE refunds AUTO_INCREMENT = 1;
ALTER TABLE customer_portal_links AUTO_INCREMENT = 1;
ALTER TABLE delivery_notes AUTO_INCREMENT = 1;
ALTER TABLE delivery_note_items AUTO_INCREMENT = 1;
ALTER TABLE document_email_logs AUTO_INCREMENT = 1;
ALTER TABLE document_maintenance_runs AUTO_INCREMENT = 1;
