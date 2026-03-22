<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2023- Mapiah Ltda
//
// Daily aggregation and retention rollup.
// Run once per day at 03:00 UTC via Hostinger cron:
//   0 3 * * * php /home/user/mapiah-telemetry-server/cron/rollup.php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/db.php';
require_once dirname(__DIR__) . '/src/json_merge.php';

$pdo = getDB();

rollupDailyToMonthly($pdo);
purgeOrphanDailyRows($pdo);

echo date('Y-m-d H:i:s') . " Rollup complete.\n";

// ---------------------------------------------------------------------------

/**
 * Aggregates daily_totals rows into monthly_totals.
 * A month is only processed when its last recorded day is also older than
 * 366 days, ensuring no partial months are rolled up prematurely.
 * The table may temporarily hold up to ~397 rows (366 + up to 31 days waiting
 * for a month-end to cross the threshold) — this is expected and harmless.
 */
function rollupDailyToMonthly(PDO $pdo): void {
    $cutoff = date('Y-m-d', strtotime('-366 days'));

    // Find calendar months where every known day is older than the cutoff.
    $stmt = $pdo->prepare(
        "SELECT DATE_FORMAT(day, '%Y-%m-01') AS month_start
         FROM daily_totals
         WHERE day <= :cutoff
         GROUP BY month_start
         HAVING MAX(day) <= :cutoff"
    );
    $stmt->execute([':cutoff' => $cutoff]);
    $months = $stmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($months as $monthStart) {
        $nextMonth = date('Y-m-01', strtotime($monthStart . ' +1 month'));

        $stmt = $pdo->prepare(
            'SELECT * FROM daily_totals
             WHERE day >= ? AND day < ?
             ORDER BY day'
        );
        $stmt->execute([$monthStart, $nextMonth]);
        $days = $stmt->fetchAll();

        if (empty($days)) {
            continue;
        }

        $agg = aggregateRows($days);

        $pdo->prepare(
            'INSERT INTO monthly_totals
               (month, user_days,
                linux_users, macos_users, windows_users,
                appimage_users, flatpak_users, other_users,
                th2_files, th2_opens, th2_minutes,
                thconfig_files, therion_runs, therion_secs,
                versions_json, distros_json, wm_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               user_days      = user_days      + VALUES(user_days),
               linux_users    = linux_users    + VALUES(linux_users),
               macos_users    = macos_users    + VALUES(macos_users),
               windows_users  = windows_users  + VALUES(windows_users),
               appimage_users = appimage_users + VALUES(appimage_users),
               flatpak_users  = flatpak_users  + VALUES(flatpak_users),
               other_users    = other_users    + VALUES(other_users),
               th2_files      = th2_files      + VALUES(th2_files),
               th2_opens      = th2_opens      + VALUES(th2_opens),
               th2_minutes    = th2_minutes    + VALUES(th2_minutes),
               thconfig_files = thconfig_files + VALUES(thconfig_files),
               therion_runs   = therion_runs   + VALUES(therion_runs),
               therion_secs   = therion_secs   + VALUES(therion_secs),
               versions_json  = VALUES(versions_json),
               distros_json   = VALUES(distros_json),
               wm_json        = VALUES(wm_json)'
        )->execute(flattenAgg($monthStart, $agg));

        $pdo->prepare(
            'DELETE FROM daily_totals WHERE day >= ? AND day < ?'
        )->execute([$monthStart, $nextMonth]);

        echo "  Rolled daily -> monthly: $monthStart\n";
    }
}

/**
 * Deletes any daily rows older than 366 days that were not part of a complete
 * month eligible for rollup (e.g. the very first days of data collection if
 * data started mid-month and that partial month never filled in).
 */
function purgeOrphanDailyRows(PDO $pdo): void {
    $cutoff = date('Y-m-d', strtotime('-366 days'));
    $stmt   = $pdo->prepare('DELETE FROM daily_totals WHERE day <= ?');
    $stmt->execute([$cutoff]);
    $deleted = $stmt->rowCount();

    if ($deleted > 0) {
        echo "  Purged $deleted orphan daily row(s) older than $cutoff.\n";
    }
}

/**
 * Aggregates an array of daily_totals DB rows into a single summary array.
 * Numeric columns are summed; JSON count maps are merged.
 */
function aggregateRows(array $rows): array {
    $agg = [
        'user_days'      => 0, 'linux_users'    => 0, 'macos_users'    => 0,
        'windows_users'  => 0, 'appimage_users'  => 0, 'flatpak_users'  => 0,
        'other_users'    => 0, 'th2_files'      => 0, 'th2_opens'      => 0,
        'th2_minutes'    => 0, 'thconfig_files'  => 0, 'therion_runs'   => 0,
        'therion_secs'   => 0,
        'versions_json'  => null,
        'distros_json'   => null,
        'wm_json'        => null,
    ];

    foreach ($rows as $row) {
        foreach ([
            'user_days', 'linux_users', 'macos_users', 'windows_users',
            'appimage_users', 'flatpak_users', 'other_users',
            'th2_files', 'th2_opens', 'th2_minutes',
            'thconfig_files', 'therion_runs', 'therion_secs',
        ] as $col) {
            $agg[$col] += (int)$row[$col];
        }

        $agg['versions_json'] = mergeCountMaps(
            $agg['versions_json'],
            decodeCountMap($row['versions_json']),
        );
        $agg['distros_json'] = mergeCountMaps(
            $agg['distros_json'],
            decodeCountMap($row['distros_json']),
        );
        $agg['wm_json'] = mergeCountMaps(
            $agg['wm_json'],
            decodeCountMap($row['wm_json']),
        );
    }

    return $agg;
}

/**
 * Returns the flat parameter array for a monthly_totals upsert,
 * with the month key prepended.
 */
function flattenAgg(string $monthKey, array $agg): array {
    return [
        $monthKey,
        $agg['user_days'],
        $agg['linux_users'],    $agg['macos_users'],    $agg['windows_users'],
        $agg['appimage_users'], $agg['flatpak_users'],  $agg['other_users'],
        $agg['th2_files'],      $agg['th2_opens'],      $agg['th2_minutes'],
        $agg['thconfig_files'], $agg['therion_runs'],   $agg['therion_secs'],
        $agg['versions_json'] !== null ? json_encode($agg['versions_json']) : null,
        $agg['distros_json']  !== null ? json_encode($agg['distros_json'])  : null,
        $agg['wm_json']       !== null ? json_encode($agg['wm_json'])       : null,
    ];
}
