-- Moje židle 2026 – MariaDB schema
-- All timestamps (DATETIME columns and JSON dates) are stored in UTC.

-- Runs (dates) of the event. Seats, reservations and VIP guests belong to a run.
CREATE TABLE IF NOT EXISTS runs (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  label             VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'Optional name, e.g. Premiéra',
  starts_at         DATETIME     NOT NULL COMMENT 'UTC',
  booking_closes_at DATETIME     NULL COMMENT 'UTC; NULL = at the start',
  storno_rules      TEXT         NOT NULL DEFAULT '[]' COMMENT 'JSON [{"from": "YYYY-MM-DDTHH:MM:SSZ" (UTC), "percent": 50}]',
  PRIMARY KEY (id),
  KEY idx_starts (starts_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE IF NOT EXISTS reservations (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  token           CHAR(32)     NOT NULL,
  run_id          INT UNSIGNED NOT NULL,
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
  checked_in_by   VARCHAR(100) NULL COMMENT 'Scanner invite name that checked the ticket in',
  PRIMARY KEY (id),
  UNIQUE KEY uq_token (token),
  UNIQUE KEY uq_vs (variable_symbol),
  KEY idx_status_expires (status, expires_at),
  KEY idx_run (run_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- One row per currently held seat of a run (pending or paid reservation).
-- The primary key makes double booking of a seat in the same run impossible; rows are deleted when a
-- reservation expires or is cancelled, which frees the seat.
CREATE TABLE IF NOT EXISTS reservation_seats (
  run_id         INT UNSIGNED NOT NULL,
  seat_id        VARCHAR(12)  NOT NULL,
  reservation_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (run_id, seat_id),
  KEY idx_reservation (reservation_id),
  CONSTRAINT fk_seat_reservation FOREIGN KEY (reservation_id)
    REFERENCES reservations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- VIP guests entered by the management. They do not pay and do not hold
-- specific seats; organizers find them by name at the entrance.
CREATE TABLE IF NOT EXISTS vip_guests (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  run_id        INT UNSIGNED NOT NULL,
  name          VARCHAR(200) NOT NULL,
  section       CHAR(2)      NOT NULL COMMENT 'Section id, e.g. ML',
  persons       TINYINT UNSIGNED NOT NULL DEFAULT 1,
  note          VARCHAR(255) NOT NULL DEFAULT '',
  created_at    DATETIME     NOT NULL,
  checked_in_at DATETIME     NULL,
  checked_in_by VARCHAR(100) NULL,
  PRIMARY KEY (id),
  KEY idx_section (section),
  KEY idx_run (run_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Rate limiting for spam/bot protection (fixed time windows).
CREATE TABLE IF NOT EXISTS rate_limits (
  bucket       CHAR(64)     NOT NULL COMMENT 'sha256 of the limited key (e.g. action + IP)',
  hits         INT UNSIGNED NOT NULL,
  window_start DATETIME     NOT NULL,
  PRIMARY KEY (bucket),
  KEY idx_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=ascii;

-- Admin-editable settings (JSON values); currently unused, runs hold dates and storno rules.
CREATE TABLE IF NOT EXISTS settings (
  name  VARCHAR(64) NOT NULL,
  value TEXT        NOT NULL,
  PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Scanner invitations: admin invites organizers (devices) for specific runs.
CREATE TABLE IF NOT EXISTS scanner_invites (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name         VARCHAR(100) NOT NULL COMMENT 'e.g. Vchod A – Petr',
  token_hash   CHAR(64)     NOT NULL COMMENT 'sha256 of the invite token (the token is shown only once)',
  created_at   DATETIME     NOT NULL,
  last_used_at DATETIME     NULL,
  revoked_at   DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_token (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE IF NOT EXISTS scanner_invite_runs (
  invite_id INT UNSIGNED NOT NULL,
  run_id    INT UNSIGNED NOT NULL,
  PRIMARY KEY (invite_id, run_id),
  CONSTRAINT fk_invite_run FOREIGN KEY (invite_id) REFERENCES scanner_invites (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
