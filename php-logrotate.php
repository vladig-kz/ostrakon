<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 Vladimir Ignatov
/*
[general]
state_file = php-logrotate.state
date_format = Y-m-d

[/var/log/test.log]
max_size = 1048576
rotate = 5
compress = true
*/

// determine the script path
$scriptPath = realpath($_SERVER['SCRIPT_FILENAME'] ?? $argv[0]);
if ($scriptPath === false) {
    fwrite(STDERR, "Cannot determine script path\n");
    exit(1);
}
$scriptDir = dirname($scriptPath);

// determine the config path
if ($argc >= 2) {
    $arg = $argv[1];

    if (strpos($arg, '/') !== false) {
        // explicit path
        $configFile = $arg;
    } else {
        // name only — look next to the script
        $configFile = $scriptDir . '/' . $arg;
    }
} else {
    // no argument given
    $configFile = $scriptDir . '/php-logrotate.conf';
}

if (!file_exists($configFile)) {
    fwrite(STDERR, "Config not found: $configFile\n");
    exit(1);
}

$config = parse_ini_file($configFile, true);

$general = $config['general'] ?? [];
$dateFormat = $general['date_format'] ?? 'Y-m-d';

// determine state_file
$configDir = dirname(realpath($configFile));

if (!empty($general['state_file'])) {
    $stateArg = $general['state_file'];

    if (strpos($stateArg, '/') !== false) {
        // explicit path
        $stateFile = $stateArg;
    } else {
        // name only — next to the config
        $stateFile = $configDir . '/' . $stateArg;
    }
} else {
    // not set — default next to the config
    $stateFile = $configDir . '/php-logrotate.state';
}

unset($config['general']);

$state = [];
if (file_exists($stateFile)) {
    $state = json_decode(file_get_contents($stateFile), true) ?? [];
}

$today = gmdate($dateFormat); // UTC — consistent with the UTC timestamps written inside the log

$configDir = dirname(realpath($configFile));

foreach ($config as $logPath => $settings) {

    // resolve the real log path
    if (strpos($logPath, '/') === 0) {
        $logFile = $logPath;
    } else {
        $logFile = $configDir . '/' . $logPath;
    }

    if (!file_exists($logFile)) {
        continue;
    }

    $maxSize  = (int)($settings['max_size'] ?? 1048576);
    $rotate   = (int)($settings['rotate'] ?? 5);
    $compress = filter_var($settings['compress'] ?? false, FILTER_VALIDATE_BOOLEAN);

    $fileSize = filesize($logFile);
    $lastRotated = $state[$logFile] ?? null;

    $rotateBySize = $fileSize >= $maxSize;
    $rotateByDate = $lastRotated !== $today;

    if (!$rotateBySize && !$rotateByDate) {
        continue;
    }

    // delete the oldest file
    $oldest = $logFile . '.' . $rotate . ($compress ? '.gz' : '');
    if (file_exists($oldest)) {
        unlink($oldest);
    }

    // shift the archives
    for ($i = $rotate - 1; $i >= 1; $i--) {
        $src = $logFile . '.' . $i . ($compress ? '.gz' : '');
        $dst = $logFile . '.' . ($i + 1) . ($compress ? '.gz' : '');

        if (file_exists($src)) {
            rename($src, $dst);
        }
    }

    // rotate the current log
    $rotated = $logFile . '.1';
    rename($logFile, $rotated);

    // create a new one
    touch($logFile);
    chmod($logFile, 0644);

    if ($compress) {
        $data = file_get_contents($rotated);
        file_put_contents($rotated . '.gz', gzencode($data, 9));
        unlink($rotated);
    }

    $state[$logFile] = $today;
}

// save state
file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT));

exit(0);
