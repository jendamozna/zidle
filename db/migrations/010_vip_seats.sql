-- VIP guests hold specific seats: a seat row belongs either to a reservation
-- or to a VIP guest; the (run_id, seat_id) primary key still prevents double booking.
ALTER TABLE vip_guests ADD COLUMN IF NOT EXISTS seats TEXT NOT NULL DEFAULT '' COMMENT 'Comma separated seat ids held for the guest (empty for guests added before seats existed)' AFTER section;

ALTER TABLE reservation_seats MODIFY reservation_id INT UNSIGNED NULL;
ALTER TABLE reservation_seats ADD COLUMN IF NOT EXISTS vip_guest_id INT UNSIGNED NULL AFTER reservation_id;
ALTER TABLE reservation_seats ADD KEY IF NOT EXISTS idx_vip (vip_guest_id);
SET @fk := (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_seat_vip');
SET @sql := IF(@fk = 0,
  'ALTER TABLE reservation_seats ADD CONSTRAINT fk_seat_vip FOREIGN KEY (vip_guest_id) REFERENCES vip_guests (id) ON DELETE CASCADE',
  'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
