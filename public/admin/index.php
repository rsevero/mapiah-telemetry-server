<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2023- Mapiah Ltda
//
// Read-only admin dashboard.
// Protected by HTTP Basic Auth via .htaccess + .htpasswd.

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/db.php';
require_once dirname(__DIR__, 2) . '/src/json_merge.php';

$pdo = getDB();

// --- Consent summary -------------------------------------------------------

$consentStmt = $pdo->query(
    "SELECT event_type, COUNT(*) AS cnt FROM consent_events GROUP BY event_type"
);
$consentCounts = ['opt_in' => 0, 'opt_out' => 0];

foreach ($consentStmt->fetchAll() as $row) {
    $consentCounts[$row['event_type']] = (int)$row['cnt'];
}

$consentRecent = $pdo->prepare(
    "SELECT event_type, COUNT(*) AS cnt FROM consent_events
     WHERE event_at >= DATE_SUB(NOW(), INTERVAL ? DAY) GROUP BY event_type"
);

$consentRecent->execute([30]);
$recent30 = ['opt_in' => 0, 'opt_out' => 0];

foreach ($consentRecent->fetchAll() as $row) {
    $recent30[$row['event_type']] = (int)$row['cnt'];
}

$consentRecent->execute([7]);
$recent7 = ['opt_in' => 0, 'opt_out' => 0];

foreach ($consentRecent->fetchAll() as $row) {
    $recent7[$row['event_type']] = (int)$row['cnt'];
}

// --- Last 30 days (daily_totals) -------------------------------------------

$dailyRows = $pdo->query(
    'SELECT * FROM daily_totals ORDER BY day DESC LIMIT 30'
)->fetchAll();
$dailyRows = array_reverse($dailyRows);

// --- All months (monthly_totals) -------------------------------------------

$monthlyRows = $pdo->query(
    'SELECT * FROM monthly_totals ORDER BY month ASC'
)->fetchAll();

// --- Last-30-days aggregates for distribution charts -----------------------

$dist30 = $pdo->query(
    "SELECT
       SUM(linux_users)    AS linux,
       SUM(macos_users)    AS macos,
       SUM(windows_users)  AS windows,
       SUM(appimage_users) AS appimage,
       SUM(flatpak_users)  AS flatpak,
       SUM(other_users)    AS other,
       SUM(user_days)      AS total
     FROM daily_totals
     WHERE day >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)"
)->fetch();

// Merge versions/distros/wm JSON from last 30 daily rows.
$versions30 = [];
$distros30  = [];
$wms30      = [];

foreach ($dailyRows as $row) {
    $versions30 = mergeCountMaps($versions30, decodeCountMap($row['versions_json']));
    $distros30  = mergeCountMaps($distros30,  decodeCountMap($row['distros_json']));
    $wms30      = mergeCountMaps($wms30,      decodeCountMap($row['wm_json']));
}

arsort($versions30);
arsort($distros30);
arsort($wms30);

$versions30 = array_slice($versions30, 0, 10, true);
$distros30  = array_slice($distros30,  0, 15, true);
$wms30      = array_slice($wms30,      0, 10, true);

// --- Helper ----------------------------------------------------------------

function h(mixed $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function jsArray(array $values): string {
    return '[' . implode(',', array_map(fn($v) => json_encode($v), $values)) . ']';
}

function jsLabels(array $assoc): string {
    return jsArray(array_keys($assoc));
}

function jsValues(array $assoc): string {
    return jsArray(array_values($assoc));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Mapiah Telemetry Dashboard</title>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
  <style>
    *, *::before, *::after { box-sizing: border-box; }
    body { font-family: system-ui, sans-serif; margin: 0; padding: 1rem 2rem; background: #f5f5f5; color: #222; }
    h1 { margin-bottom: 0.25rem; }
    .subtitle { color: #666; margin-bottom: 2rem; font-size: 0.9rem; }
    h2 { margin: 2rem 0 0.75rem; border-bottom: 2px solid #ddd; padding-bottom: 0.25rem; }
    .cards { display: flex; gap: 1rem; flex-wrap: wrap; margin-bottom: 1rem; }
    .card { background: #fff; border-radius: 8px; padding: 1rem 1.5rem; box-shadow: 0 1px 3px #0002; min-width: 160px; }
    .card .label { font-size: 0.8rem; color: #666; text-transform: uppercase; letter-spacing: .05em; }
    .card .value { font-size: 2rem; font-weight: 700; margin-top: 0.25rem; }
    .card .value.positive { color: #2a7; }
    .card .value.negative { color: #c33; }
    .chart-wrap { background: #fff; border-radius: 8px; padding: 1.25rem; box-shadow: 0 1px 3px #0002; margin-bottom: 1.5rem; }
    .chart-wrap canvas { max-height: 300px; }
    .two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; }
    table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 3px #0002; }
    th, td { padding: 0.5rem 1rem; text-align: left; border-bottom: 1px solid #eee; font-size: 0.9rem; }
    th { background: #f0f0f0; font-weight: 600; }
    tr:last-child td { border-bottom: none; }
    @media (max-width: 700px) { .two-col { grid-template-columns: 1fr; } }
  </style>
</head>
<body>

<h1>Mapiah Telemetry Dashboard</h1>
<p class="subtitle">Generated <?= h(date('Y-m-d H:i:s')) ?> UTC</p>

<!-- ===== Consent summary ===== -->
<h2>Consent</h2>
<div class="cards">
  <div class="card">
    <div class="label">Total opt-ins</div>
    <div class="value positive"><?= h($consentCounts['opt_in']) ?></div>
  </div>
  <div class="card">
    <div class="label">Total opt-outs</div>
    <div class="value negative"><?= h($consentCounts['opt_out']) ?></div>
  </div>
  <div class="card">
    <div class="label">Net participants</div>
    <?php $net = $consentCounts['opt_in'] - $consentCounts['opt_out']; ?>
    <div class="value <?= $net >= 0 ? 'positive' : 'negative' ?>"><?= h($net) ?></div>
  </div>
  <div class="card">
    <div class="label">Opt-ins last 30 days</div>
    <div class="value positive"><?= h($recent30['opt_in']) ?></div>
  </div>
  <div class="card">
    <div class="label">Opt-ins last 7 days</div>
    <div class="value positive"><?= h($recent7['opt_in']) ?></div>
  </div>
</div>

<!-- ===== Active user-days over time ===== -->
<h2>Active user-days</h2>

<div class="chart-wrap">
  <canvas id="chartDaily"></canvas>
</div>
<div class="chart-wrap">
  <canvas id="chartMonthly"></canvas>
</div>

<!-- ===== OS and build type distributions ===== -->
<h2>Distributions (last 30 days)</h2>
<div class="two-col">
  <div class="chart-wrap">
    <canvas id="chartOS"></canvas>
  </div>
  <div class="chart-wrap">
    <canvas id="chartBuild"></canvas>
  </div>
</div>

<!-- ===== Version distribution ===== -->
<h2>Mapiah version distribution (last 30 days)</h2>
<div class="chart-wrap">
  <canvas id="chartVersions"></canvas>
</div>

<!-- ===== TH2 and Therion usage ===== -->
<h2>TH2 usage (last 30 days, per user-day)</h2>
<div class="chart-wrap">
  <canvas id="chartTH2"></canvas>
</div>

<h2>Therion usage (last 30 days, per user-day)</h2>
<div class="chart-wrap">
  <canvas id="chartTherion"></canvas>
</div>

<!-- ===== Monthly history ===== -->
<h2>Monthly history</h2>
<div class="chart-wrap">
  <canvas id="chartMonthlyHistory"></canvas>
</div>

<!-- ===== Detail tables ===== -->
<h2>Linux distros (last 30 days)</h2>
<table>
  <tr><th>Distro</th><th>Count</th></tr>
  <?php foreach ($distros30 as $distro => $count): ?>
  <tr><td><?= h($distro) ?></td><td><?= h($count) ?></td></tr>
  <?php endforeach; ?>
  <?php if (empty($distros30)): ?><tr><td colspan="2">No data</td></tr><?php endif; ?>
</table>

<br>
<h2>Window managers (last 30 days)</h2>
<table>
  <tr><th>Window manager</th><th>Count</th></tr>
  <?php foreach ($wms30 as $wm => $count): ?>
  <tr><td><?= h($wm) ?></td><td><?= h($count) ?></td></tr>
  <?php endforeach; ?>
  <?php if (empty($wms30)): ?><tr><td colspan="2">No data</td></tr><?php endif; ?>
</table>

<script>
// --- Daily user-days (last 30 days) ---
new Chart(document.getElementById('chartDaily'), {
  type: 'bar',
  data: {
    labels: <?= jsArray(array_column($dailyRows, 'day')) ?>,
    datasets: [{
      label: 'User-days',
      data:  <?= jsArray(array_column($dailyRows, 'user_days')) ?>,
      backgroundColor: 'rgba(42, 130, 200, 0.7)',
    }]
  },
  options: { plugins: { title: { display: true, text: 'Active user-days — last 30 days' } }, scales: { y: { beginAtZero: true } } }
});

// --- Monthly user-days (all months) ---
new Chart(document.getElementById('chartMonthly'), {
  type: 'bar',
  data: {
    labels: <?= jsArray(array_column($monthlyRows, 'month')) ?>,
    datasets: [{
      label: 'User-days',
      data:  <?= jsArray(array_column($monthlyRows, 'user_days')) ?>,
      backgroundColor: 'rgba(42, 130, 200, 0.7)',
    }]
  },
  options: { plugins: { title: { display: true, text: 'Active user-days — all months (archived)' } }, scales: { y: { beginAtZero: true } } }
});

// --- OS distribution ---
new Chart(document.getElementById('chartOS'), {
  type: 'doughnut',
  data: {
    labels: ['Linux', 'macOS', 'Windows'],
    datasets: [{
      data: [<?= h($dist30['linux'] ?? 0) ?>, <?= h($dist30['macos'] ?? 0) ?>, <?= h($dist30['windows'] ?? 0) ?>],
      backgroundColor: ['#f5a623', '#7ed321', '#4a90e2'],
    }]
  },
  options: { plugins: { title: { display: true, text: 'OS distribution' } } }
});

// --- Build type distribution ---
new Chart(document.getElementById('chartBuild'), {
  type: 'doughnut',
  data: {
    labels: ['AppImage', 'Flatpak', 'Other'],
    datasets: [{
      data: [<?= h($dist30['appimage'] ?? 0) ?>, <?= h($dist30['flatpak'] ?? 0) ?>, <?= h($dist30['other'] ?? 0) ?>],
      backgroundColor: ['#9b59b6', '#e74c3c', '#95a5a6'],
    }]
  },
  options: { plugins: { title: { display: true, text: 'Build type distribution' } } }
});

// --- Version distribution ---
new Chart(document.getElementById('chartVersions'), {
  type: 'bar',
  data: {
    labels: <?= jsLabels($versions30) ?>,
    datasets: [{
      label: 'User-days',
      data:  <?= jsValues($versions30) ?>,
      backgroundColor: 'rgba(155, 89, 182, 0.7)',
    }]
  },
  options: {
    indexAxis: 'y',
    plugins: { title: { display: true, text: 'Top 10 Mapiah versions' }, legend: { display: false } },
    scales: { x: { beginAtZero: true } }
  }
});

// --- TH2 usage per user-day ---
<?php
$th2Labels   = array_column($dailyRows, 'day');
$th2Minutes  = array_map(fn($r) => $r['user_days'] > 0 ? round($r['th2_minutes'] / $r['user_days'], 1) : 0, $dailyRows);
$th2Opens    = array_map(fn($r) => $r['user_days'] > 0 ? round($r['th2_opens']   / $r['user_days'], 1) : 0, $dailyRows);
?>
new Chart(document.getElementById('chartTH2'), {
  type: 'line',
  data: {
    labels: <?= jsArray($th2Labels) ?>,
    datasets: [
      { label: 'Avg minutes open', data: <?= jsArray($th2Minutes) ?>, borderColor: '#2a7', tension: 0.3, fill: false },
      { label: 'Avg opens',        data: <?= jsArray($th2Opens)   ?>, borderColor: '#e74', tension: 0.3, fill: false },
    ]
  },
  options: { plugins: { title: { display: true, text: 'TH2 usage per user-day' } }, scales: { y: { beginAtZero: true } } }
});

// --- Therion usage per user-day ---
<?php
$therionRuns = array_map(fn($r) => $r['user_days'] > 0 ? round($r['therion_runs'] / $r['user_days'], 2) : 0, $dailyRows);
$therionSecs = array_map(fn($r) => $r['user_days'] > 0 ? round($r['therion_secs'] / $r['user_days'], 1) : 0, $dailyRows);
?>
new Chart(document.getElementById('chartTherion'), {
  type: 'line',
  data: {
    labels: <?= jsArray($th2Labels) ?>,
    datasets: [
      { label: 'Avg runs',         data: <?= jsArray($therionRuns) ?>, borderColor: '#4a90e2', tension: 0.3, fill: false },
      { label: 'Avg seconds',      data: <?= jsArray($therionSecs) ?>, borderColor: '#f5a623', tension: 0.3, fill: false },
    ]
  },
  options: { plugins: { title: { display: true, text: 'Therion usage per user-day' } }, scales: { y: { beginAtZero: true } } }
});

// --- Monthly history (user-days stacked by OS) ---
new Chart(document.getElementById('chartMonthlyHistory'), {
  type: 'bar',
  data: {
    labels: <?= jsArray(array_column($monthlyRows, 'month')) ?>,
    datasets: [
      { label: 'Linux',   data: <?= jsArray(array_column($monthlyRows, 'linux_users'))   ?>, backgroundColor: '#f5a623' },
      { label: 'macOS',   data: <?= jsArray(array_column($monthlyRows, 'macos_users'))   ?>, backgroundColor: '#7ed321' },
      { label: 'Windows', data: <?= jsArray(array_column($monthlyRows, 'windows_users')) ?>, backgroundColor: '#4a90e2' },
    ]
  },
  options: {
    plugins: { title: { display: true, text: 'Monthly user-days by OS (archived)' } },
    scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true } }
  }
});
</script>

</body>
</html>
