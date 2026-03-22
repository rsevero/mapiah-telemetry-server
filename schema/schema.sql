-- SPDX-License-Identifier: GPL-3.0-or-later
-- Copyright (C) 2023- Mapiah Ltda
--
-- Mapiah Telemetry Server — database schema
-- Run once: mysql -u user -p mapiah_telemetry < schema/schema.sql

-- Aggregated daily totals (one row per calendar day; kept 366 days).
CREATE TABLE IF NOT EXISTS daily_totals (
  day               DATE NOT NULL PRIMARY KEY,
  user_days         INT UNSIGNED NOT NULL DEFAULT 0,
  linux_users       INT UNSIGNED NOT NULL DEFAULT 0,
  macos_users       INT UNSIGNED NOT NULL DEFAULT 0,
  windows_users     INT UNSIGNED NOT NULL DEFAULT 0,
  appimage_users    INT UNSIGNED NOT NULL DEFAULT 0,
  flatpak_users     INT UNSIGNED NOT NULL DEFAULT 0,
  other_users       INT UNSIGNED NOT NULL DEFAULT 0,
  th2_files         INT UNSIGNED NOT NULL DEFAULT 0,
  th2_opens         INT UNSIGNED NOT NULL DEFAULT 0,
  th2_minutes       INT UNSIGNED NOT NULL DEFAULT 0,
  thconfig_files    INT UNSIGNED NOT NULL DEFAULT 0,
  therion_runs      INT UNSIGNED NOT NULL DEFAULT 0,
  therion_secs      BIGINT UNSIGNED NOT NULL DEFAULT 0,
  versions_json     JSON NULL,
  distros_json      JSON NULL,
  wm_json           JSON NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Monthly totals (one row per calendar month; kept forever).
CREATE TABLE IF NOT EXISTS monthly_totals (
  month             DATE NOT NULL PRIMARY KEY,
  user_days         INT UNSIGNED NOT NULL DEFAULT 0,
  linux_users       INT UNSIGNED NOT NULL DEFAULT 0,
  macos_users       INT UNSIGNED NOT NULL DEFAULT 0,
  windows_users     INT UNSIGNED NOT NULL DEFAULT 0,
  appimage_users    INT UNSIGNED NOT NULL DEFAULT 0,
  flatpak_users     INT UNSIGNED NOT NULL DEFAULT 0,
  other_users       INT UNSIGNED NOT NULL DEFAULT 0,
  th2_files         BIGINT UNSIGNED NOT NULL DEFAULT 0,
  th2_opens         BIGINT UNSIGNED NOT NULL DEFAULT 0,
  th2_minutes       BIGINT UNSIGNED NOT NULL DEFAULT 0,
  thconfig_files    BIGINT UNSIGNED NOT NULL DEFAULT 0,
  therion_runs      BIGINT UNSIGNED NOT NULL DEFAULT 0,
  therion_secs      BIGINT UNSIGNED NOT NULL DEFAULT 0,
  versions_json     JSON NULL,
  distros_json      JSON NULL,
  wm_json           JSON NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Anonymous opt-in / opt-out events; kept forever.
CREATE TABLE IF NOT EXISTS consent_events (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_type  ENUM('opt_in', 'opt_out') NOT NULL,
  event_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_type_date (event_type, event_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Per-IP-hash rate limiting (rolling 1-minute windows).
CREATE TABLE IF NOT EXISTS rate_limits (
  ip_hash      CHAR(64) NOT NULL,
  endpoint     VARCHAR(30) NOT NULL,
  window_start DATETIME NOT NULL,
  hit_count    SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (ip_hash, endpoint, window_start),
  INDEX idx_cleanup (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
