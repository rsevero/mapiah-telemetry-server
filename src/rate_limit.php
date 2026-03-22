<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2023- Mapiah Ltda

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/response.php';

const RATE_LIMIT_MAX_HITS    = 10;
const RATE_LIMIT_WINDOW_SECS = 60;

/**
 * Check rate limit for the given endpoint.
 * Hashes the client IP before any DB interaction — the raw IP is never stored.
 * Calls jsonError(429, …) and exits if the limit is exceeded.
 */
function checkRateLimit(string $endpoint): void {
    $ip      = $_SERVER['REMOTE_ADDR'] ?? '';
    $ipHash  = hash('sha256', $ip);
    $window  = date('Y-m-d H:i:s', (int)(time() / RATE_LIMIT_WINDOW_SECS) * RATE_LIMIT_WINDOW_SECS);

    $pdo = getDB();

    // Upsert hit count for this (ip_hash, endpoint, window).
    $stmt = $pdo->prepare(
        'INSERT INTO rate_limits (ip_hash, endpoint, window_start, hit_count)
         VALUES (?, ?, ?, 1)
         ON DUPLICATE KEY UPDATE hit_count = hit_count + 1'
    );
    $stmt->execute([$ipHash, $endpoint, $window]);

    // Read back the current hit count.
    $stmt = $pdo->prepare(
        'SELECT hit_count FROM rate_limits
         WHERE ip_hash = ? AND endpoint = ? AND window_start = ?'
    );
    $stmt->execute([$ipHash, $endpoint, $window]);
    $row = $stmt->fetch();

    if ($row && (int)$row['hit_count'] > RATE_LIMIT_MAX_HITS) {
        jsonError(429, 'Rate limit exceeded. Try again in a minute.');
    }

    // Opportunistically clean up old windows (1-in-50 chance to avoid overhead on every request).
    if (random_int(1, 50) === 1) {
        $cutoff = date('Y-m-d H:i:s', time() - RATE_LIMIT_WINDOW_SECS * 2);
        $pdo->prepare('DELETE FROM rate_limits WHERE window_start < ?')->execute([$cutoff]);
    }
}
