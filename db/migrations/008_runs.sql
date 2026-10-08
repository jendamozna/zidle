-- The event has several runs (dates). Seats, reservations and VIP guests
-- belong to a run; storno rules are per run.
CREATE TABLE IF NOT EXISTS runs (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  label             VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'Optional name, e.g. Premiéra',
  starts_at         DATETIME     NOT NULL COMMENT 'UTC',
  booking_closes_at DATETIME     NULL COMMENT 'UTC; NULL = at the start',
  storno_rules      TEXT         NOT NULL DEFAULT '[]' COMMENT 'JSON [{"from": "YYYY-MM-DD HH:MM" (Europe/Prague), "percent": 50}]',
  PRIMARY KEY (id),
  KEY idx_starts (starts_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Existing data goes to a first run created from the former single event
-- date and storno rules (check and correct it in admin → Nastavení).
INSERT IGNORE INTO runs (id, label, starts_at, storno_rules)
SELECT 1, '',
  COALESCE(
    (SELECT COALESCE(CONVERT_TZ(STR_TO_DATE(JSON_UNQUOTE(value), '%Y-%m-%d %H:%i'), 'Europe/Prague', 'UTC'),
                     CONVERT_TZ(STR_TO_DATE(JSON_UNQUOTE(value), '%Y-%m-%d %H:%i'), '+01:00', '+00:00'))
       FROM settings WHERE name = 'event_at' AND JSON_UNQUOTE(value) <> ''),
    UTC_TIMESTAMP() + INTERVAL 30 DAY),
  COALESCE((SELECT value FROM settings WHERE name = 'storno_rules'), '[]');
DELETE FROM settings WHERE name IN ('event_at', 'storno_rules');

ALTER TABLE reservations ADD COLUMN IF NOT EXISTS run_id INT UNSIGNED NULL AFTER token;
UPDATE reservations SET run_id = (SELECT MIN(id) FROM runs) WHERE run_id IS NULL;
ALTER TABLE reservations MODIFY run_id INT UNSIGNED NOT NULL, ADD KEY IF NOT EXISTS idx_run (run_id);

ALTER TABLE reservation_seats ADD COLUMN IF NOT EXISTS run_id INT UNSIGNED NULL FIRST;
UPDATE reservation_seats rs JOIN reservations r ON r.id = rs.reservation_id SET rs.run_id = r.run_id WHERE rs.run_id IS NULL;
ALTER TABLE reservation_seats MODIFY run_id INT UNSIGNED NOT NULL, DROP PRIMARY KEY, ADD PRIMARY KEY (run_id, seat_id);

ALTER TABLE vip_guests ADD COLUMN IF NOT EXISTS run_id INT UNSIGNED NULL AFTER id;
UPDATE vip_guests SET run_id = (SELECT MIN(id) FROM runs) WHERE run_id IS NULL;
ALTER TABLE vip_guests MODIFY run_id INT UNSIGNED NOT NULL, ADD KEY IF NOT EXISTS idx_run (run_id);
