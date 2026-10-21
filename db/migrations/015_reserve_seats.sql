-- New seating layout: seats can be "Záložní" (not for sale) per run, chosen by the admin (Plánek).
ALTER TABLE runs
  ADD COLUMN IF NOT EXISTS reserve_seats TEXT NULL COMMENT 'JSON seat ids that are "Záložní" (not for sale); NULL = the reserve rows of the layout' AFTER storno_rules,
  DROP COLUMN IF EXISTS open_reserves;
