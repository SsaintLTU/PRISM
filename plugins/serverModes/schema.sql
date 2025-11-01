-- Schema for the PRISM serverModes plugin
-- This file mirrors the automatic schema bootstrap performed by ServerModes_Database::ensureSchema().
-- Execute it against a MySQL 5.7+ or MariaDB 10.3+ database before enabling the plugin for best performance.

CREATE TABLE IF NOT EXISTS prism_players (
    user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    username VARCHAR(32) NOT NULL DEFAULT '',
    nickname VARCHAR(32) NOT NULL DEFAULT '',
    total_distance DOUBLE NOT NULL DEFAULT 0,
    total_earnings DOUBLE NOT NULL DEFAULT 0,
    total_xp DOUBLE NOT NULL DEFAULT 0,
    lap_count INT UNSIGNED NOT NULL DEFAULT 0,
    last_mode VARCHAR(16) NOT NULL DEFAULT '',
    last_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS prism_player_snapshots (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    mode VARCHAR(16) NOT NULL,
    distance DOUBLE NOT NULL DEFAULT 0,
    earnings DOUBLE NOT NULL DEFAULT 0,
    xp DOUBLE NOT NULL DEFAULT 0,
    lap_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    CONSTRAINT fk_snapshots_player FOREIGN KEY (user_id) REFERENCES prism_players(user_id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS prism_player_state (
    user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    state_json LONGTEXT NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_state_player FOREIGN KEY (user_id) REFERENCES prism_players(user_id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS prism_player_friends (
    owner_id BIGINT UNSIGNED NOT NULL,
    friend_id BIGINT UNSIGNED NOT NULL,
    friend_name VARCHAR(32) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (owner_id, friend_id),
    INDEX idx_friend_id (friend_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS prism_drift_scores (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    username VARCHAR(32) NOT NULL DEFAULT '',
    nickname VARCHAR(32) NOT NULL DEFAULT '',
    layout VARCHAR(8) NOT NULL DEFAULT '',
    host_id VARCHAR(32) NOT NULL DEFAULT '',
    points DOUBLE NOT NULL DEFAULT 0,
    max_angle DOUBLE NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_layout_created (layout, created_at),
    INDEX idx_user_layout (user_id, layout)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS prism_race_results (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    username VARCHAR(32) NOT NULL DEFAULT '',
    nickname VARCHAR(32) NOT NULL DEFAULT '',
    track VARCHAR(8) NOT NULL DEFAULT '',
    car VARCHAR(8) NOT NULL DEFAULT '',
    class VARCHAR(16) NOT NULL DEFAULT '',
    position TINYINT UNSIGNED NOT NULL DEFAULT 0,
    points INT UNSIGNED NOT NULL DEFAULT 0,
    race_time DOUBLE NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_track (user_id, track),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS prism_vehicle_mods (
    id VARCHAR(32) NOT NULL PRIMARY KEY,
    short_name VARCHAR(32) NOT NULL DEFAULT '',
    display_name VARCHAR(128) NOT NULL DEFAULT '',
    car_code VARCHAR(32) NOT NULL DEFAULT '',
    hex_code VARCHAR(64) NOT NULL DEFAULT '',
    category VARCHAR(64) NOT NULL DEFAULT '',
    author VARCHAR(64) NOT NULL DEFAULT '',
    power_kw DOUBLE NOT NULL DEFAULT 0,
    weight_kg DOUBLE NOT NULL DEFAULT 0,
    price DOUBLE NOT NULL DEFAULT 0,
    raw_json LONGTEXT NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_car_code (car_code),
    INDEX idx_hex_code (hex_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
