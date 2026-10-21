-- New seating layout with reserve seats on the balcony (not for sale until the admin opens them per run).
ALTER TABLE runs
  ADD COLUMN IF NOT EXISTS open_reserves TEXT NOT NULL DEFAULT '[]' COMMENT 'JSON ids of sections whose reserve seats are for sale, e.g. ["BL"]' AFTER storno_rules;
