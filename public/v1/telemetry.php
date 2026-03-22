<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2023- Mapiah Ltda
//
// POST /v1/telemetry
// Accepts an array of aggregated daily usage records from Mapiah clients.

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/response.php';
require_once dirname(__DIR__, 2) . '/src/rate_limit.php';
require_once dirname(__DIR__, 2) . '/src/validate.php';
require_once dirname(__DIR__, 2) . '/src/upsert.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError(405, 'Method not allowed.');
}

checkRateLimit('telemetry');

// Enforce body size limit.
$raw = file_get_contents('php://input', length: 65536 + 1);

if (strlen($raw) > 65536) {
    jsonError(400, 'Request body too large.');
}

$body = json_decode($raw, true);

if (!is_array($body) || !isset($body['records']) || !is_array($body['records'])) {
    jsonError(400, 'Invalid JSON: expected {"records": [...]}.');
}

if (count($body['records']) > MAX_RECORDS_PER_POST) {
    jsonError(400, 'Too many records in one request.');
}

// Process each record; silently skip invalid ones.
foreach ($body['records'] as $rawRecord) {
    $record = validateRecord($rawRecord);

    if ($record === null) {
        continue;
    }

    upsertDailyRecord($record);
}

jsonOk();
