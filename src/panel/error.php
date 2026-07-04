<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 Vladimir Ignatov
/**
 * Error page.
 * @var int    $code HTTP code
 * @var string $msg  message (already localized)
 */
$lng = Panel::lang();
?>
<div class="card center">
    <h1><?= htmlspecialchars(Lang::get('panel_error_title', $lng, ['code' => $code])) ?></h1>
    <p><?= htmlspecialchars($msg) ?></p>
    <p><a class="btn-link" href="<?= htmlspecialchars(Panel::baseUrl()) ?>/admin"><?= htmlspecialchars(Lang::get('panel_to_home', $lng)) ?></a></p>
</div>
