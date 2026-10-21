-- Vstupenky na TZG 2026 – MariaDB schema
-- All timestamps (DATETIME columns and JSON dates) are stored in UTC.

-- Runs (dates) of the event. Seats and reservations belong to a run.
CREATE TABLE IF NOT EXISTS runs (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  label             VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'Optional name, e.g. Premiéra',
  starts_at         DATETIME     NOT NULL COMMENT 'UTC',
  booking_closes_at DATETIME     NULL COMMENT 'UTC; NULL = at the start',
  storno_rules      TEXT         NOT NULL DEFAULT '[]' COMMENT 'JSON [{"from": "YYYY-MM-DDTHH:MM:SSZ" (UTC), "percent": 50}]',
  reserve_seats     TEXT         NULL COMMENT 'JSON seat ids that are "Záložní" (not for sale); NULL = the reserve rows of the layout',
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
  child_seats     TEXT         NOT NULL DEFAULT '' COMMENT 'Seats with a child ticket (4–17 years), comma separated; the others are adult',
  free_seats      TEXT         NOT NULL DEFAULT '' COMMENT 'Seats paid by a discount code (price 0), comma separated',
  ticket_names    TEXT         NOT NULL DEFAULT '{}' COMMENT 'JSON {seat: name on the ticket} set by the customer; others show the reservation name',
  seat_count      SMALLINT UNSIGNED NOT NULL,
  adult_price     INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'CZK per adult seat when the reservation was made',
  child_price     INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'CZK per child seat when the reservation was made',
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
  checked_in_at   DATETIME     NULL COMMENT 'First check-in of any seat of the reservation (ticket_checkins)',
  checked_in_by   VARCHAR(100) NULL COMMENT 'Scanner invite name of that first check-in',
  sold_by         VARCHAR(100) NULL COMMENT 'Sold at the entrance by this organizer (scanner); NULL = booked on the website',
  payment_checked_at DATETIME  NULL COMMENT 'UTC; accountant confirmed that the payment of an on-site sale arrived',
  PRIMARY KEY (id),
  UNIQUE KEY uq_token (token),
  UNIQUE KEY uq_vs (variable_symbol),
  KEY idx_status_expires (status, expires_at),
  KEY idx_run (run_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- One row per checked-in seat ticket (one QR code = one seat).
CREATE TABLE IF NOT EXISTS ticket_checkins (
  reservation_id INT UNSIGNED NOT NULL,
  seat_id        VARCHAR(12)  NOT NULL,
  checked_in_at  DATETIME     NOT NULL COMMENT 'UTC',
  checked_in_by  VARCHAR(100) NOT NULL,
  PRIMARY KEY (reservation_id, seat_id),
  CONSTRAINT fk_checkin_reservation FOREIGN KEY (reservation_id) REFERENCES reservations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Discount codes sent by the admin to e-mail addresses. One code = one seat free
-- (the most expensive seat of the reservation), any run, used once.
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

-- One row per currently held seat of a run (a pending or paid reservation's seat).
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

-- Check-ins recorded by scanners without a connection that could not be applied
-- when they were synchronised (e.g. the same ticket let in on two devices).
CREATE TABLE IF NOT EXISTS scan_conflicts (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  run_id         INT UNSIGNED NOT NULL,
  reservation_id INT UNSIGNED NULL,
  label          VARCHAR(250) NOT NULL COMMENT 'Which ticket it was about (VS + name + seat), kept for display',
  reason         ENUM('already_checked_in', 'not_paid', 'unknown') NOT NULL,
  scanned_at     DATETIME     NOT NULL COMMENT 'UTC, time of the offline scan on the device',
  scanned_by     VARCHAR(100) NOT NULL,
  other_at       DATETIME     NULL COMMENT 'UTC, the earlier check-in that won',
  other_by       VARCHAR(100) NULL,
  created_at     DATETIME     NOT NULL COMMENT 'UTC, when the scan was synchronised',
  PRIMARY KEY (id),
  KEY idx_run (run_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Admin accounts (accountants): invited by e-mail, the invitee sets a password;
-- the e-mail is the login. ADMIN_PASSWORD stays as the master login.
CREATE TABLE IF NOT EXISTS admin_users (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email             VARCHAR(190) NOT NULL COMMENT 'Login, lower case',
  name              VARCHAR(100) NOT NULL,
  password_hash     VARCHAR(255) NULL COMMENT 'password_hash(); NULL until the invitation is accepted',
  invite_hash       CHAR(64)     NULL COMMENT 'sha256 of the open invitation token',
  invite_expires_at DATETIME     NULL COMMENT 'UTC',
  invited_by        VARCHAR(100) NOT NULL DEFAULT '',
  created_at        DATETIME     NOT NULL COMMENT 'UTC',
  accepted_at       DATETIME     NULL COMMENT 'UTC, first password set',
  last_login_at     DATETIME     NULL COMMENT 'UTC',
  disabled_at       DATETIME     NULL COMMENT 'UTC; disabled accounts cannot sign in',
  PRIMARY KEY (id),
  UNIQUE KEY uq_email (email),
  UNIQUE KEY uq_invite (invite_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
