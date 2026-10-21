-- Customers can no longer cancel reservations themselves (only the admin, with a full refund),
-- so storno rules and fees go away.
ALTER TABLE runs DROP COLUMN IF EXISTS storno_rules;
ALTER TABLE reservations DROP COLUMN IF EXISTS cancelled_by, DROP COLUMN IF EXISTS cancel_fee;
