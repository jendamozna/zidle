-- Adult / child seats, infants on a lap, discount codes, one ticket (QR code) per seat and on-site sales.
ALTER TABLE reservations
  ADD COLUMN IF NOT EXISTS child_seats TEXT NOT NULL DEFAULT '' COMMENT 'Seats with a child ticket (4–17 years), comma separated; the others are adult' AFTER cancelled_seats,
  ADD COLUMN IF NOT EXISTS free_seats  TEXT NOT NULL DEFAULT '' COMMENT 'Seats paid by a discount code (price 0), comma separated' AFTER child_seats,
  ADD COLUMN IF NOT EXISTS infants     TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Children up to 3 years on a lap: no seat, no ticket, free' AFTER free_seats,
  ADD COLUMN IF NOT EXISTS adult_price INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'CZK per adult seat when the reservation was made' AFTER seat_count,
  ADD COLUMN IF NOT EXISTS child_price INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'CZK per child seat when the reservation was made' AFTER adult_price,
  ADD COLUMN IF NOT EXISTS sold_by     VARCHAR(100) NULL COMMENT 'Sold at the entrance by this organizer (scanner); NULL = booked on the website' AFTER checked_in_by,
  ADD COLUMN IF NOT EXISTS payment_checked_at DATETIME NULL COMMENT 'UTC; accountant confirmed that the payment of an on-site sale arrived' AFTER sold_by;

-- Existing reservations: all seats adult at the price they were booked for.
UPDATE reservations SET adult_price = amount DIV seat_count, child_price = amount DIV seat_count
  WHERE adult_price = 0 AND seat_count > 0;

CREATE TABLE IF NOT EXISTS ticket_checkins (
  reservation_id INT UNSIGNED NOT NULL,
  seat_id        VARCHAR(12)  NOT NULL,
  checked_in_at  DATETIME     NOT NULL COMMENT 'UTC',
  checked_in_by  VARCHAR(100) NOT NULL,
  PRIMARY KEY (reservation_id, seat_id),
  CONSTRAINT fk_checkin_reservation FOREIGN KEY (reservation_id) REFERENCES reservations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Reservations checked in with the former one-QR ticket: all their seats count as checked in.
INSERT IGNORE INTO ticket_checkins (reservation_id, seat_id, checked_in_at, checked_in_by)
  SELECT r.id, rs.seat_id, r.checked_in_at, COALESCE(r.checked_in_by, '')
  FROM reservations r JOIN reservation_seats rs ON rs.reservation_id = r.id
  WHERE r.checked_in_at IS NOT NULL;

CREATE TABLE IF NOT EXISTS discount_codes (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code           CHAR(8)      NOT NULL COMMENT 'Upper case, shown as XXXX-XXXX',
  email          VARCHAR(190) NOT NULL COMMENT 'Where the code was sent (emptied by the GDPR purge)',
  created_at     DATETIME     NOT NULL COMMENT 'UTC',
  created_by     VARCHAR(100) NOT NULL DEFAULT '',
  emailed_at     DATETIME     NULL COMMENT 'UTC, last successful e-mail with the code',
  used_at        DATETIME     NULL COMMENT 'UTC',
  reservation_id INT UNSIGNED NULL,
  seat_id        VARCHAR(12)  NULL COMMENT 'Seat that became free with the code',
  revoked_at     DATETIME     NULL COMMENT 'UTC; revoked codes cannot be used',
  PRIMARY KEY (id),
  UNIQUE KEY uq_code (code),
  KEY idx_reservation (reservation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
