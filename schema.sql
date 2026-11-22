-- nCore figyelő – MySQL séma (csurgay_ncore adatbázis)

CREATE TABLE IF NOT EXISTS watch_items (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name             VARCHAR(200) NOT NULL,
    keywords         VARCHAR(255) NOT NULL DEFAULT '',
    kind             ENUM('film','sorozat') NOT NULL DEFAULT 'sorozat',
    lang             ENUM('any','HUN','ENG') NOT NULL DEFAULT 'HUN',
    quality          ENUM('any','SD','720p','1080p','2160p') NOT NULL DEFAULT 'any',
    season           SMALLINT UNSIGNED NULL,
    last_episode     SMALLINT UNSIGNED NULL,
    stop_after_match TINYINT(1) NOT NULL DEFAULT 0,
    active           TINYINT(1) NOT NULL DEFAULT 1,
    note             VARCHAR(255) NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_match_at    DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS seen_torrents (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    item_id     INT UNSIGNED NOT NULL,
    guid        VARCHAR(64) NOT NULL,
    title       VARCHAR(255) NOT NULL,
    link        VARCHAR(255) NULL,
    notified_at DATETIME NOT NULL,
    UNIQUE KEY uq_item_guid (item_id, guid),
    KEY idx_notified (notified_at),
    CONSTRAINT fk_seen_item FOREIGN KEY (item_id) REFERENCES watch_items (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS run_log (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    run_at     DATETIME NOT NULL,
    items_read INT NOT NULL DEFAULT 0,
    matches    INT NOT NULL DEFAULT 0,
    error      VARCHAR(500) NULL,
    KEY idx_run_at (run_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
