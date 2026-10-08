-- Moje židle 2026 – MariaDB schema
-- All timestamps are stored in UTC.

CREATE TABLE IF NOT EXISTS reservations (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  token           CHAR(32)     NOT NULL,
  first_name      VARCHAR(100) NOT NULL,
  last_name       VARCHAR(100) NOT NULL,
  email           VARCHAR(190) NOT NULL,
  seats           TEXT         NOT NULL COMMENT 'Comma separated seat ids currently in the reservation (kept after cancellation for history)',
  cancelled_seats TEXT         NOT NULL DEFAULT '' COMMENT 'Seats cancelled individually, comma separated',
  seat_count      SMALLINT UNSIGNED NOT NULL,
  amount          INT UNSIGNED NOT NULL COMMENT 'CZK, price of the seats currently in the reservation',
  paid_amount     INT UNSIGNED NULL COMMENT 'CZK received',
  variable_symbol VARCHAR(10)  NULL,
  status          ENUM('pending', 'paid', 'expired', 'cancelled') NOT NULL DEFAULT 'pending',
  created_at      DATETIME     NOT NULL,
  expires_at      DATETIME     NOT NULL,
  paid_at         DATETIME     NULL,
  cancelled_at    DATETIME     NULL,
  cancelled_by    ENUM('customer', 'admin') NULL,
  cancel_fee      INT UNSIGNED NULL COMMENT 'CZK kept as storno fee',
  refund_amount   INT UNSIGNED NULL COMMENT 'CZK to return to the paying account in total (refund due = refund_amount - refunded_amount)',
  refunded_amount INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'CZK already returned',
  refunded_at     DATETIME     NULL,
  ticket_sent_at  DATETIME     NULL COMMENT 'When the e-mail with the ticket QR code was sent',
  reminder_sent_at      DATETIME NULL,
  expiry_notice_sent_at DATETIME NULL,
  checked_in_at   DATETIME     NULL COMMENT 'First scan of the ticket at the entrance',
  PRIMARY KEY (id),
  UNIQUE KEY uq_token (token),
  UNIQUE KEY uq_vs (variable_symbol),
  KEY idx_status_expires (status, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- One row per currently held seat (pending or paid reservation).
-- The primary key makes double booking impossible; rows are deleted when a
-- reservation expires or is cancelled, which frees the seat.
CREATE TABLE IF NOT EXISTS reservation_seats (
  seat_id        VARCHAR(12)  NOT NULL,
  reservation_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (seat_id),
  KEY idx_reservation (reservation_id),
  CONSTRAINT fk_seat_reservation FOREIGN KEY (reservation_id)
    REFERENCES reservations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- VIP guests entered by the management. They do not pay and do not hold
-- specific seats; organizers find them by name at the entrance.
CREATE TABLE IF NOT EXISTS vip_guests (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name          VARCHAR(200) NOT NULL,
  section       CHAR(2)      NOT NULL COMMENT 'Section id, e.g. ML',
  persons       TINYINT UNSIGNED NOT NULL DEFAULT 1,
  note          VARCHAR(255) NOT NULL DEFAULT '',
  created_at    DATETIME     NOT NULL,
  checked_in_at DATETIME     NULL,
  PRIMARY KEY (id),
  KEY idx_section (section)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Rate limiting for spam/bot protection (fixed time windows).
CREATE TABLE IF NOT EXISTS rate_limits (
  bucket       CHAR(64)     NOT NULL COMMENT 'sha256 of the limited key (e.g. action + IP)',
  hits         INT UNSIGNED NOT NULL,
  window_start DATETIME     NOT NULL,
  PRIMARY KEY (bucket),
  KEY idx_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=ascii;

-- Admin-editable settings (event date, storno rules), JSON values.
CREATE TABLE IF NOT EXISTS settings (
  name  VARCHAR(64) NOT NULL,
  value TEXT        NOT NULL,
  PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
