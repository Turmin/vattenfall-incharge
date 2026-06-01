CREATE TABLE IF NOT EXISTS incharge_favorite_chargepoints (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    chargepoint_name VARCHAR(40) NOT NULL,
    display_name VARCHAR(160) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_chargepoint_name (chargepoint_name),
    INDEX active_sort (is_active, sort_order, display_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS incharge_chargepoint_snapshots (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    favorite_id INT UNSIGNED NOT NULL,
    measured_at DATETIME NOT NULL,
    status VARCHAR(50) NOT NULL,
    status_bucket VARCHAR(20) NOT NULL,
    available_connectors INT NULL,
    occupied_connectors INT NULL,
    total_connectors INT NULL,
    raw_json JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_favorite_time (favorite_id, measured_at),
    INDEX idx_favorite_status_time (favorite_id, status_bucket, measured_at),
    INDEX idx_measured_at (measured_at),
    CONSTRAINT fk_incharge_snapshots_favorite
        FOREIGN KEY (favorite_id)
        REFERENCES incharge_favorite_chargepoints(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS incharge_cron_jobs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    task VARCHAR(40) NOT NULL,
    schedule VARCHAR(40) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    last_run_at DATETIME NULL,
    last_status VARCHAR(20) NULL,
    last_message TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX enabled_schedule (enabled, schedule),
    INDEX last_run_at (last_run_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
