-- Accounts get roles (admin = Správce, accountant = Účetní, scanner = Odbavovatel) and a
-- forgotten-password token. Existing accounts had all rights → Správce. Organizers now sign
-- in to the scanner with their own accounts, so scanner invite links go away.
ALTER TABLE admin_users
  ADD COLUMN IF NOT EXISTS roles VARCHAR(40) NOT NULL DEFAULT 'admin' COMMENT 'CSV of admin, accountant, scanner' AFTER name,
  ADD COLUMN IF NOT EXISTS reset_hash CHAR(64) NULL COMMENT 'sha256 of the open forgotten-password token' AFTER invite_expires_at,
  ADD COLUMN IF NOT EXISTS reset_expires_at DATETIME NULL COMMENT 'UTC' AFTER reset_hash,
  ADD UNIQUE KEY IF NOT EXISTS uq_reset (reset_hash);
ALTER TABLE admin_users MODIFY COLUMN roles VARCHAR(40) NOT NULL DEFAULT '' COMMENT 'CSV of admin, accountant, scanner';
DROP TABLE IF EXISTS scanner_invite_runs;
DROP TABLE IF EXISTS scanner_invites;
