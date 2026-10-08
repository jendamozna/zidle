-- Admin accounts (accountants): invited by e-mail, the invitee sets a password;
-- the e-mail is the login. ADMIN_PASSWORD stays as the master login.
CREATE TABLE IF NOT EXISTS admin_users (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email             VARCHAR(190) NOT NULL COMMENT 'Login, lower case',
  name              VARCHAR(100) NOT NULL,
  password_hash     VARCHAR(255) NULL COMMENT 'password_hash(); NULL until the invitation is accepted',
  invite_hash       CHAR(64)     NULL COMMENT 'sha256 of the open invitation token',
  invite_expires_at DATETIME     NULL COMMENT 'UTC',
  invited_by        VARCHAR(100) NOT NULL DEFAULT '',
  created_at        DATETIME     NOT NULL COMMENT 'UTC',
  accepted_at       DATETIME     NULL COMMENT 'UTC, first password set',
  last_login_at     DATETIME     NULL COMMENT 'UTC',
  disabled_at       DATETIME     NULL COMMENT 'UTC; disabled accounts cannot sign in',
  PRIMARY KEY (id),
  UNIQUE KEY uq_email (email),
  UNIQUE KEY uq_invite (invite_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
