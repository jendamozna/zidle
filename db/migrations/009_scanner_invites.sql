-- Scanner invitations: admin invites organizers (devices) for specific runs.
CREATE TABLE IF NOT EXISTS scanner_invites (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name         VARCHAR(100) NOT NULL COMMENT 'e.g. Vchod A – Petr',
  token_hash   CHAR(64)     NOT NULL COMMENT 'sha256 of the invite token (the token is shown only once)',
  created_at   DATETIME     NOT NULL COMMENT 'UTC',
  last_used_at DATETIME     NULL COMMENT 'UTC',
  revoked_at   DATETIME     NULL COMMENT 'UTC',
  PRIMARY KEY (id),
  UNIQUE KEY uq_token (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE IF NOT EXISTS scanner_invite_runs (
  invite_id INT UNSIGNED NOT NULL,
  run_id    INT UNSIGNED NOT NULL,
  PRIMARY KEY (invite_id, run_id),
  CONSTRAINT fk_invite_run FOREIGN KEY (invite_id) REFERENCES scanner_invites (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- Who checked a ticket / VIP guest in (invite name or "hlavní heslo").
ALTER TABLE reservations ADD COLUMN IF NOT EXISTS checked_in_by VARCHAR(100) NULL AFTER checked_in_at;
ALTER TABLE vip_guests   ADD COLUMN IF NOT EXISTS checked_in_by VARCHAR(100) NULL AFTER checked_in_at;
