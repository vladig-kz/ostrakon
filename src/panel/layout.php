<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 Vladimir Ignatov
/**
 * Common page shell of the panel (Bulma + a thin admin.css on top).
 * @var string $title   page title
 * @var string $content already-rendered content
 */
$base = Panel::baseUrl();
$lng  = Panel::lang();
$me   = PanelAuth::user();
?><!doctype html>
<html lang="<?= htmlspecialchars($lng) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title ?? 'Ostrakon') ?> — Ostrakon</title>
    <link rel="icon" type="image/svg+xml" href="<?= htmlspecialchars($base) ?>/assets/favicon.svg">
    <link rel="icon" type="image/png" href="<?= htmlspecialchars($base) ?>/assets/favicon.png">
    <link rel="apple-touch-icon" href="<?= htmlspecialchars($base) ?>/assets/favicon.png">
    <link rel="stylesheet" href="<?= htmlspecialchars($base) ?>/assets/bulma.min.css">
    <link rel="stylesheet" href="<?= htmlspecialchars($base) ?>/assets/admin.css">
</head>
<body>
<header class="topbar">
    <a class="brand" href="<?= htmlspecialchars($base) ?>/admin">
        <img class="brand-mark" src="<?= htmlspecialchars($base) ?>/assets/favicon.svg" alt="" width="26" height="26">Ostrakon</a>
    <span class="spacer"></span>
    <?php foreach (Lang::available() as $code => $name): ?>
        <?php if ($code === $lng): ?>
            <span class="btn-link lang-current"><?= htmlspecialchars($name) ?></span>
        <?php else: ?>
            <a class="btn-link" href="<?= htmlspecialchars($base) ?>/admin/setlang/<?= htmlspecialchars(rawurlencode($code)) ?>"><?= htmlspecialchars($name) ?></a>
        <?php endif; ?>
    <?php endforeach; ?>
    <?php if ($me !== null): ?>
        <a class="btn-link" href="<?= htmlspecialchars($base) ?>/admin/help"><?= htmlspecialchars(Lang::get('panel_help', $lng)) ?></a>
        <span class="me"><?= htmlspecialchars(($me['name'] !== '' ? $me['name'] : '') ?: ('@' . ($me['username'] ?? (string) $me['id']))) ?></span>
        <a class="btn-link" href="<?= htmlspecialchars($base) ?>/admin/logout"><?= htmlspecialchars(Lang::get('panel_logout', $lng)) ?></a>
    <?php endif; ?>
</header>
<section class="section">
    <div class="container">
        <?= $content ?? '' ?>
    </div>
</section>
<footer class="section py-4 has-text-centered">
    <a class="btn-link" href="<?= htmlspecialchars($base) ?>/admin/privacy"><?= htmlspecialchars(Lang::get('panel_privacy', $lng)) ?></a>
</footer>
</body>
</html>
