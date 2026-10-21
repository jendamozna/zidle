-- Organizer creates a password on the first opening of the scanner invite link;
-- afterwards the link works only with it (a new link clears it).
ALTER TABLE scanner_invites ADD COLUMN IF NOT EXISTS password_hash VARCHAR(255) NULL AFTER token_hash;
