<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2023- Mapiah Ltda

declare(strict_types=1);

/**
 * Merges two JSON count maps of the form {"key": int, ...}.
 * Each key's value in the result is the sum of its values from both maps.
 * Null inputs are treated as empty maps.
 */
function mergeCountMaps(?array $a, ?array $b): array {
    $result = $a ?? [];

    foreach (($b ?? []) as $key => $count) {
        $result[$key] = ($result[$key] ?? 0) + (int)$count;
    }

    return $result;
}

/**
 * Decodes a JSON string (or null) into a PHP array suitable for mergeCountMaps.
 * Returns null if the input is null or decoding fails.
 */
function decodeCountMap(?string $json): ?array {
    if ($json === null) {
        return null;
    }

    $decoded = json_decode($json, true);

    return is_array($decoded) ? $decoded : null;
}
