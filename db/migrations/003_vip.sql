-- VIP guests (added after the first release).
CREATE TABLE IF NOT EXISTS vip_guests (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name          VARCHAR(200) NOT NULL,
  section       CHAR(2)      NOT NULL COMMENT 'Section id, e.g. ML',
  persons       TINYINT UNSIGNED NOT NULL DEFAULT 1,
  note          VARCHAR(255) NOT NULL DEFAULT '',
  created_at    DATETIME     NOT NULL,
  checked_in_at DATETIME     NULL,
  PRIMARY KEY (id),
  KEY idx_section (section)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
