-- Cancelling individual seats; refunds tracked as amounts (several partial refunds possible).
ALTER TABLE reservations
  ADD COLUMN IF NOT EXISTS paid_amount     INT UNSIGNED NULL COMMENT 'CZK received' AFTER amount,
  ADD COLUMN IF NOT EXISTS cancelled_seats TEXT NOT NULL DEFAULT '' COMMENT 'Seats cancelled individually, comma separated' AFTER seats,
  ADD COLUMN IF NOT EXISTS refunded_amount INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'CZK already returned' AFTER refund_account;

UPDATE reservations SET paid_amount = amount WHERE paid_at IS NOT NULL AND paid_amount IS NULL;
UPDATE reservations SET refunded_amount = refund_amount WHERE refunded_at IS NOT NULL AND refund_amount IS NOT NULL AND refunded_amount = 0;
