<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2023- Mapiah Ltda

declare(strict_types=1);

function jsonOk(): never {
    header('Content-Type: application/json');
    http_response_code(200);
    echo '{}';
    exit;
}

function jsonError(int $status, string $message): never {
    header('Content-Type: application/json');
    http_response_code($status);
    echo json_encode(['error' => $message]);
    exit;
}
