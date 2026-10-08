-- Check-ins recorded by scanners without a connection that could not be applied
-- when they were synchronised (e.g. the same ticket let in on two devices).
CREATE TABLE IF NOT EXISTS scan_conflicts (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  run_id         INT UNSIGNED NOT NULL,
  reservation_id INT UNSIGNED NULL,
  vip_guest_id   INT UNSIGNED NULL,
  label          VARCHAR(250) NOT NULL COMMENT 'Who it was about (VS + name, or VIP name), kept for display',
  reason         ENUM('already_checked_in', 'not_paid', 'unknown') NOT NULL,
  scanned_at     DATETIME     NOT NULL COMMENT 'UTC, time of the offline scan on the device',
  scanned_by     VARCHAR(100) NOT NULL,
  other_at       DATETIME     NULL COMMENT 'UTC, the earlier check-in that won',
  other_by       VARCHAR(100) NULL,
  created_at     DATETIME     NOT NULL COMMENT 'UTC, when the scan was synchronised',
  PRIMARY KEY (id),
  KEY idx_run (run_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
