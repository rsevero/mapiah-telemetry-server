<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2023- Mapiah Ltda
//
// POST /v1/telemetry/opt-in
// Records an anonymous opt-in event (increments counter only; no user data stored).

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/response.php';
require_once dirname(__DIR__, 2) . '/src/rate_limit.php';
require_once dirname(__DIR__, 2) . '/src/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError(405, 'Method not allowed.');
}

checkRateLimit('opt-in');

getDB()
    ->prepare('INSERT INTO consent_events (event_type) VALUES (?)')
    ->execute(['opt_in']);

jsonOk();
