-- VIP guests removed (free seats are given with discount codes now): their held seats are
-- released and the table is dropped.
DELETE FROM reservation_seats WHERE reservation_id IS NULL;
ALTER TABLE reservation_seats DROP FOREIGN KEY IF EXISTS fk_seat_vip;
ALTER TABLE reservation_seats DROP INDEX IF EXISTS idx_vip;
ALTER TABLE reservation_seats DROP COLUMN IF EXISTS vip_guest_id;
ALTER TABLE reservation_seats MODIFY reservation_id INT UNSIGNED NOT NULL;
DELETE FROM scan_conflicts WHERE label LIKE 'VIP %';
ALTER TABLE scan_conflicts DROP COLUMN IF EXISTS vip_guest_id;
DROP TABLE IF EXISTS vip_guests;
