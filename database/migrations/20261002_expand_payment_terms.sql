-- Expand supported client/invoice payment terms.
ALTER TABLE clients
  MODIFY payment_terms ENUM('due_on_receipt','net_7','net_15','net_30')
  NOT NULL DEFAULT 'due_on_receipt';

ALTER TABLE invoices
  MODIFY payment_terms ENUM('due_on_receipt','net_7','net_15','net_30')
  NOT NULL DEFAULT 'due_on_receipt';
