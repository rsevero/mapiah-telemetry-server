<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2023- Mapiah Ltda

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/json_merge.php';

/**
 * Upserts a validated DailyRecord into daily_totals.
 * Uses a transaction with SELECT … FOR UPDATE to avoid lost-update races
 * when multiple client submissions arrive for the same day.
 */
function upsertDailyRecord(array $record): void {
    $pdo = getDB();
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare(
            'SELECT * FROM daily_totals WHERE day = ? FOR UPDATE'
        );
        $stmt->execute([$record['date']]);
        $existing = $stmt->fetch();

        // Build the JSON count maps for the incoming record.
        $newVersions = [$record['mapiah_version'] => 1];
        $newDistros  = ($record['linux_distro'] !== null)   ? [$record['linux_distro']   => 1] : [];
        $newWMs      = ($record['window_manager'] !== null) ? [$record['window_manager'] => 1] : [];

        if ($existing === false) {
            // No row yet for this day — insert.
            $stmt = $pdo->prepare(
                'INSERT INTO daily_totals
                   (day, user_days,
                    linux_users, macos_users, windows_users,
                    appimage_users, flatpak_users, other_users,
                    th2_files, th2_opens, th2_minutes,
                    thconfig_files, therion_runs, therion_secs,
                    versions_json, distros_json, wm_json)
                 VALUES
                   (?, 1,
                    ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?)'
            );
            $stmt->execute([
                $record['date'],
                (int)($record['os_type'] === 'linux'),
                (int)($record['os_type'] === 'macos'),
                (int)($record['os_type'] === 'windows'),
                (int)($record['build_type'] === 'AppImage'),
                (int)($record['build_type'] === 'Flatpak'),
                (int)($record['build_type'] === 'Other'),
                $record['th2_files'],
                $record['th2_opens'],
                $record['th2_minutes'],
                $record['thconfig_files'],
                $record['therion_runs'],
                $record['therion_secs'],
                json_encode($newVersions),
                empty($newDistros) ? null : json_encode($newDistros),
                empty($newWMs)     ? null : json_encode($newWMs),
            ]);
        } else {
            // Row exists — add numeric fields and merge JSON maps.
            $mergedVersions = mergeCountMaps(decodeCountMap($existing['versions_json']), $newVersions);
            $mergedDistros  = mergeCountMaps(decodeCountMap($existing['distros_json']),  $newDistros);
            $mergedWMs      = mergeCountMaps(decodeCountMap($existing['wm_json']),        $newWMs);

            $stmt = $pdo->prepare(
                'UPDATE daily_totals SET
                   user_days      = user_days      + 1,
                   linux_users    = linux_users    + ?,
                   macos_users    = macos_users    + ?,
                   windows_users  = windows_users  + ?,
                   appimage_users = appimage_users + ?,
                   flatpak_users  = flatpak_users  + ?,
                   other_users    = other_users    + ?,
                   th2_files      = th2_files      + ?,
                   th2_opens      = th2_opens      + ?,
                   th2_minutes    = th2_minutes    + ?,
                   thconfig_files = thconfig_files + ?,
                   therion_runs   = therion_runs   + ?,
                   therion_secs   = therion_secs   + ?,
                   versions_json  = ?,
                   distros_json   = ?,
                   wm_json        = ?
                 WHERE day = ?'
            );
            $stmt->execute([
                (int)($record['os_type'] === 'linux'),
                (int)($record['os_type'] === 'macos'),
                (int)($record['os_type'] === 'windows'),
                (int)($record['build_type'] === 'AppImage'),
                (int)($record['build_type'] === 'Flatpak'),
                (int)($record['build_type'] === 'Other'),
                $record['th2_files'],
                $record['th2_opens'],
                $record['th2_minutes'],
                $record['thconfig_files'],
                $record['therion_runs'],
                $record['therion_secs'],
                json_encode($mergedVersions),
                empty($mergedDistros) ? null : json_encode($mergedDistros),
                empty($mergedWMs)     ? null : json_encode($mergedWMs),
                $record['date'],
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
