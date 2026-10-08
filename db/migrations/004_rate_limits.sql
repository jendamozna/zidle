-- Rate limiting for spam/bot protection (fixed time windows).
CREATE TABLE IF NOT EXISTS rate_limits (
  bucket       CHAR(64)     NOT NULL COMMENT 'sha256 of the limited key (e.g. action + IP)',
  hits         INT UNSIGNED NOT NULL,
  window_start DATETIME     NOT NULL,
  PRIMARY KEY (bucket),
  KEY idx_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=ascii;
