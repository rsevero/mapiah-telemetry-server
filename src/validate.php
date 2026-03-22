<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2023- Mapiah Ltda

declare(strict_types=1);

const VALID_OS_TYPES    = ['linux', 'macos', 'windows'];
const VALID_BUILD_TYPES = ['AppImage', 'Flatpak', 'Other'];
const MAX_RECORDS_PER_POST = 400;

/**
 * Validates a single DailyRecord from the client payload.
 * Returns a sanitized associative array on success, or null if the record is invalid.
 * Invalid records are silently skipped — never reject the whole batch for one bad record.
 */
function validateRecord(mixed $raw): ?array {
    if (!is_array($raw)) {
        return null;
    }

    // Required string fields.
    $date           = $raw['date']           ?? null;
    $osType         = $raw['osType']         ?? null;
    $osVersion      = $raw['osVersion']      ?? null;
    $mapiahVersion  = $raw['mapiahVersion']  ?? null;
    $buildType      = $raw['buildType']      ?? null;

    if (
        !is_string($date)          || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ||
        !is_string($osType)        || !in_array($osType, VALID_OS_TYPES, true) ||
        !is_string($osVersion)     || strlen($osVersion) === 0 ||
        !is_string($mapiahVersion) || strlen($mapiahVersion) === 0 ||
        !is_string($buildType)     || !in_array($buildType, VALID_BUILD_TYPES, true)
    ) {
        return null;
    }

    // Validate date is a real calendar date.
    $parsed = date_create_from_format('Y-m-d', $date);

    if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
        return null;
    }

    // Optional string fields (Linux-only).
    $linuxDistro   = isset($raw['linuxDistro'])   && is_string($raw['linuxDistro'])   ? substr($raw['linuxDistro'],   0, 255) : null;
    $windowManager = isset($raw['windowManager']) && is_string($raw['windowManager']) ? substr($raw['windowManager'], 0, 255) : null;

    // Required non-negative integer fields.
    $intFields = [
        'th2DifferentFilesCount',
        'th2OpenCount',
        'th2TimeMinutes',
        'thConfigDifferentFilesCount',
        'therionRunCount',
        'therionTimeSecs',
    ];

    $ints = [];

    foreach ($intFields as $field) {
        $v = $raw[$field] ?? null;

        if (!is_int($v) || $v < 0) {
            return null;
        }

        $ints[$field] = $v;
    }

    return [
        'date'          => $date,
        'os_type'       => $osType,
        'os_version'    => substr($osVersion, 0, 255),
        'linux_distro'  => $linuxDistro,
        'window_manager'=> $windowManager,
        'mapiah_version'=> substr($mapiahVersion, 0, 50),
        'build_type'    => $buildType,
        'th2_files'     => $ints['th2DifferentFilesCount'],
        'th2_opens'     => $ints['th2OpenCount'],
        'th2_minutes'   => $ints['th2TimeMinutes'],
        'thconfig_files'=> $ints['thConfigDifferentFilesCount'],
        'therion_runs'  => $ints['therionRunCount'],
        'therion_secs'  => $ints['therionTimeSecs'],
    ];
}
