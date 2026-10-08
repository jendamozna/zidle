-- Moje židle 2026 – MariaDB schema
-- All timestamps are stored in UTC.

CREATE TABLE IF NOT EXISTS reservations (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  token           CHAR(32)     NOT NULL,
  first_name      VARCHAR(100) NOT NULL,
  last_name       VARCHAR(100) NOT NULL,
  email           VARCHAR(190) NOT NULL,
  seats           TEXT         NOT NULL COMMENT 'Comma separated seat ids, kept for history',
  seat_count      SMALLINT UNSIGNED NOT NULL,
  amount          INT UNSIGNED NOT NULL COMMENT 'CZK',
  variable_symbol VARCHAR(10)  NULL,
  status          ENUM('pending', 'paid', 'expired', 'cancelled') NOT NULL DEFAULT 'pending',
  created_at      DATETIME     NOT NULL,
  expires_at      DATETIME     NOT NULL,
  paid_at         DATETIME     NULL,
  cancelled_at    DATETIME     NULL,
  ticket_sent_at  DATETIME     NULL COMMENT 'When the e-mail with the ticket QR code was sent',
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
