-- New seating layout with optional balcony sections (closed for sale until the admin opens them per run).
ALTER TABLE runs
  ADD COLUMN IF NOT EXISTS open_sections TEXT NOT NULL DEFAULT '[]' COMMENT 'JSON ids of optional sections opened for sale, e.g. ["BV"]' AFTER storno_rules;
