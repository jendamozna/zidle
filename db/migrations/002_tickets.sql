-- Upgrade for databases created before tickets were added.
ALTER TABLE reservations
  ADD COLUMN IF NOT EXISTS ticket_sent_at DATETIME NULL COMMENT 'When the e-mail with the ticket QR code was sent' AFTER cancelled_at,
  ADD COLUMN IF NOT EXISTS checked_in_at  DATETIME NULL COMMENT 'First scan of the ticket at the entrance' AFTER ticket_sent_at;
