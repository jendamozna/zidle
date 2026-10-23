-- Koncert TZG 2026 – MariaDB schema
-- All timestamps (DATETIME columns and JSON dates) are stored in UTC.

-- Runs (dates) of the event. Seats and reservations belong to a run.
CREATE TABLE IF NOT EXISTS runs (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  label             VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'Optional name, e.g. Premiéra',
  starts_at         DATETIME     NOT NULL COMMENT 'UTC',
  booking_closes_at DATETIME     NULL COMMENT 'UTC; NULL = at the start',
  reserve_seats     TEXT         NULL COMMENT 'JSON seat ids that are "Záložní" (not for sale); NULL = the reserve rows of the layout',
  is_test           TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = test run for the organizers: hidden from the public list, bookings paid at once, deletable with its reservations',
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
  amount          INT UNSIGNED NOT NULL COMMENT 'CZK, price of the seats currently in the reservation (sum of reservation_items.price)',
  paid_amount     INT UNSIGNED NULL COMMENT 'CZK received',
  specific_symbol VARCHAR(10)  NULL COMMENT 'SS of the payment (unique, 10 digits); the VS is PAYMENT_VARIABLE_SYMBOL for all',
  status          ENUM('pending', 'paid', 'expired', 'cancelled') NOT NULL DEFAULT 'pending',
  created_at      DATETIME     NOT NULL,
  expires_at      DATETIME     NOT NULL,
  paid_at         DATETIME     NULL,
  cancelled_at    DATETIME     NULL,
  refund_amount   INT UNSIGNED NULL COMMENT 'CZK to return to the paying account in total (refund due = refund_amount - refunded_amount)',
  refunded_amount INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'CZK already returned',
  refunded_at     DATETIME     NULL,
  ticket_sent_at  DATETIME     NULL COMMENT 'When the e-mail with the ticket QR code was sent',
  reminder_sent_at      DATETIME NULL,
  expiry_notice_sent_at DATETIME NULL,
  checked_in_at   DATETIME     NULL COMMENT 'First check-in of any seat of the reservation (reservation_items)',
  checked_in_by   VARCHAR(200) NULL COMMENT 'E-mail of the account that made the first check-in',
  sold_by         VARCHAR(200) NULL COMMENT 'Sold at the entrance by this account (e-mail, scanner); NULL = booked on the website',
  payment_checked_at DATETIME  NULL COMMENT 'UTC; accountant confirmed that the payment of an on-site sale arrived',
  PRIMARY KEY (id),
  UNIQUE KEY uq_token (token),
  UNIQUE KEY uq_ss (specific_symbol),
  UNIQUE KEY uq_id_run (id, run_id) COMMENT 'Target of the reservation_seats FK (same run)',
  KEY idx_status_expires (status, expires_at),
  KEY idx_run (run_id),
  CONSTRAINT fk_reservation_run FOREIGN KEY (run_id) REFERENCES runs (id),
  CONSTRAINT chk_paid_at CHECK (status <> 'paid' OR paid_at IS NOT NULL),
  CONSTRAINT chk_cancelled_at CHECK (status NOT IN ('cancelled', 'expired') OR cancelled_at IS NOT NULL),
  CONSTRAINT chk_refunded CHECK (refunded_amount <= COALESCE(refund_amount, 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- One row per seat ever in a reservation (one QR code = one seat). A seat cancelled on its own gets
-- cancelled_at; a whole cancelled or expired reservation keeps its items as they were (history, restore).
CREATE TABLE IF NOT EXISTS reservation_items (
  reservation_id INT UNSIGNED NOT NULL,
  seat_id        VARCHAR(12)  NOT NULL,
  kind           ENUM('adult', 'child') NOT NULL DEFAULT 'adult' COMMENT 'child = ticket for 4–17 years',
  price          INT UNSIGNED NOT NULL COMMENT 'CZK of the seat when booked (0 = paid by a discount code)',
  free           TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Paid by a discount code',
  holder_name    VARCHAR(100) NULL COMMENT 'Name on the ticket set by the customer; NULL = the reservation name',
  cancelled_at   DATETIME     NULL COMMENT 'UTC; the seat was cancelled from the reservation',
  checked_in_at  DATETIME     NULL COMMENT 'UTC',
  checked_in_by  VARCHAR(200) NULL,
  PRIMARY KEY (reservation_id, seat_id),
  KEY idx_seat (seat_id),
  CONSTRAINT fk_item_reservation FOREIGN KEY (reservation_id) REFERENCES reservations (id) ON DELETE CASCADE,
  CONSTRAINT chk_free_price CHECK (free = 0 OR price = 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Discount codes sent by the admin to e-mail addresses. One code = one seat free
-- (the most expensive seat of the reservation), any run, used once.
CREATE TABLE IF NOT EXISTS discount_codes (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code           CHAR(8)      NOT NULL COMMENT 'Upper case, shown as XXXX-XXXX',
  email          VARCHAR(190) NOT NULL COMMENT 'Where the code was sent (emptied by the GDPR purge)',
  created_at     DATETIME     NOT NULL COMMENT 'UTC',
  created_by     VARCHAR(200) NOT NULL DEFAULT '',
  emailed_at     DATETIME     NULL COMMENT 'UTC, last successful e-mail with the code',
  used_at        DATETIME     NULL COMMENT 'UTC',
  reservation_id INT UNSIGNED NULL,
  seat_id        VARCHAR(12)  NULL COMMENT 'Seat that became free with the code',
  revoked_at     DATETIME     NULL COMMENT 'UTC; revoked codes cannot be used',
  PRIMARY KEY (id),
  UNIQUE KEY uq_code (code),
  UNIQUE KEY uq_reservation_seat (reservation_id, seat_id) COMMENT 'One code per free seat',
  CONSTRAINT fk_code_reservation FOREIGN KEY (reservation_id) REFERENCES reservations (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- One row per currently held seat of a run (a pending or paid reservation's seat).
-- The primary key makes double booking of a seat in the same run impossible; rows are deleted when a
-- reservation expires or is cancelled, which frees the seat.
CREATE TABLE IF NOT EXISTS reservation_seats (
  run_id         INT UNSIGNED NOT NULL,
  seat_id        VARCHAR(12)  NOT NULL,
  reservation_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (run_id, seat_id),
  KEY idx_reservation (reservation_id, run_id),
  CONSTRAINT fk_seat_reservation FOREIGN KEY (reservation_id, run_id)
    REFERENCES reservations (id, run_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Rate limiting for spam/bot protection (fixed time windows).
CREATE TABLE IF NOT EXISTS rate_limits (
  bucket       CHAR(64)     NOT NULL COMMENT 'sha256 of the limited key (e.g. action + IP)',
  hits         INT UNSIGNED NOT NULL,
  window_start DATETIME     NOT NULL,
  PRIMARY KEY (bucket),
  KEY idx_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=ascii;

-- Admin-editable settings (JSON values): payment_deadlines.
CREATE TABLE IF NOT EXISTS settings (
  name  VARCHAR(64) NOT NULL,
  value TEXT        NOT NULL,
  PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Check-ins recorded by scanners without a connection that could not be applied
-- when they were synchronised (e.g. the same ticket let in on two devices).
CREATE TABLE IF NOT EXISTS scan_conflicts (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  run_id         INT UNSIGNED NOT NULL,
  reservation_id INT UNSIGNED NULL,
  label          VARCHAR(250) NOT NULL COMMENT 'Which ticket it was about (SS + name + seat), kept for display',
  reason         ENUM('already_checked_in', 'not_paid', 'unknown') NOT NULL,
  scanned_at     DATETIME     NOT NULL COMMENT 'UTC, time of the offline scan on the device',
  scanned_by     VARCHAR(200) NOT NULL,
  other_at       DATETIME     NULL COMMENT 'UTC, the earlier check-in that won',
  other_by       VARCHAR(200) NULL,
  created_at     DATETIME     NOT NULL COMMENT 'UTC, when the scan was synchronised',
  PRIMARY KEY (id),
  KEY idx_run (run_id),
  CONSTRAINT fk_conflict_run FOREIGN KEY (run_id) REFERENCES runs (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Bank transfers recorded from an uploaded bank statement (admin → Výpis z banky),
-- so the same transaction is never recorded twice. No personal data.
CREATE TABLE IF NOT EXISTS bank_payments (
  transaction_id VARCHAR(100) NOT NULL COMMENT 'Bank transaction id ("Identifikace transakce"), or a hash of the row',
  reservation_id INT UNSIGNED NULL,
  amount         INT          NOT NULL COMMENT 'CZK',
  booked_on      DATE         NULL COMMENT 'Value date in the statement',
  imported_at    DATETIME     NOT NULL COMMENT 'UTC',
  imported_by    VARCHAR(190) NOT NULL,
  PRIMARY KEY (transaction_id),
  KEY idx_reservation (reservation_id),
  CONSTRAINT fk_bank_reservation FOREIGN KEY (reservation_id) REFERENCES reservations (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- User accounts with roles (admin = Správce, accountant = Účetní, scanner = Odbavovatel):
-- the first Správce is created by api/deploy.php, everybody else is invited by e-mail
-- and sets a password; the e-mail is the login.
CREATE TABLE IF NOT EXISTS admin_users (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email             VARCHAR(190) NOT NULL COMMENT 'Login, lower case',
  roles             VARCHAR(40)  NOT NULL DEFAULT '' COMMENT 'CSV of admin, accountant, scanner',
  password_hash     VARCHAR(255) NULL COMMENT 'password_hash(); NULL until the invitation is accepted',
  invite_hash       CHAR(64)     NULL COMMENT 'sha256 of the open invitation token',
  invite_expires_at DATETIME     NULL COMMENT 'UTC',
  reset_hash        CHAR(64)     NULL COMMENT 'sha256 of the open forgotten-password token',
  reset_expires_at  DATETIME     NULL COMMENT 'UTC',
  invited_by        VARCHAR(200) NOT NULL DEFAULT '' COMMENT 'E-mail of the inviting Správce, deploy for the first one',
  created_at        DATETIME     NOT NULL COMMENT 'UTC',
  accepted_at       DATETIME     NULL COMMENT 'UTC, first password set',
  last_login_at     DATETIME     NULL COMMENT 'UTC',
  disabled_at       DATETIME     NULL COMMENT 'UTC; disabled accounts cannot sign in',
  PRIMARY KEY (id),
  UNIQUE KEY uq_email (email),
  UNIQUE KEY uq_invite (invite_hash),
  UNIQUE KEY uq_reset (reset_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Runs an Odbavovatel may check in (0..n per account, 0..n accounts per run).
-- A Správce checks in every run without rows here.
CREATE TABLE IF NOT EXISTS user_runs (
  user_id INT UNSIGNED NOT NULL,
  run_id  INT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, run_id),
  KEY idx_run (run_id),
  CONSTRAINT fk_user_runs_user FOREIGN KEY (user_id) REFERENCES admin_users (id) ON DELETE CASCADE,
  CONSTRAINT fk_user_runs_run FOREIGN KEY (run_id) REFERENCES runs (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
