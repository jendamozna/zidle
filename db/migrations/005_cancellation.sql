-- Reminders, expiry notices, customer cancellation with storno fees, settings.
ALTER TABLE reservations
  ADD COLUMN IF NOT EXISTS reminder_sent_at      DATETIME NULL AFTER ticket_sent_at,
  ADD COLUMN IF NOT EXISTS expiry_notice_sent_at DATETIME NULL AFTER reminder_sent_at,
  ADD COLUMN IF NOT EXISTS cancelled_by   ENUM('customer', 'admin') NULL AFTER cancelled_at,
  ADD COLUMN IF NOT EXISTS cancel_fee     INT UNSIGNED NULL COMMENT 'CZK kept as storno fee' AFTER cancelled_by,
  ADD COLUMN IF NOT EXISTS refund_amount  INT UNSIGNED NULL COMMENT 'CZK to return to the customer' AFTER cancel_fee,
  ADD COLUMN IF NOT EXISTS refund_account VARCHAR(64)  NULL AFTER refund_amount,
  ADD COLUMN IF NOT EXISTS refunded_at    DATETIME     NULL AFTER refund_account;

-- Admin-editable settings (event date, storno rules), JSON values.
CREATE TABLE IF NOT EXISTS settings (
  name  VARCHAR(64) NOT NULL,
  value TEXT        NOT NULL,
  PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
