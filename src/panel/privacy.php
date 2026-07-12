<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 Vladimir Ignatov
/**
 * Public privacy notice. The text comes from the 'privacy_body' lang key (paragraphs are
 * separated by a blank line). Reachable without login; linked from the page footer and the
 * bot's /privacy command.
 */
$lng  = Panel::lang();
$base = Panel::baseUrl();
$body = Lang::get('privacy_body', $lng);
?>
<a class="button is-light mb-4" href="<?= htmlspecialchars($base) ?>/admin"><?= htmlspecialchars(Lang::get('panel_to_home', $lng)) ?></a>

<div class="box">
    <h1 class="title is-3"><?= htmlspecialchars(Lang::get('privacy_title', $lng)) ?></h1>
    <div class="content">
        <?php foreach (preg_split('/\n\n+/', trim($body)) as $para): ?>
            <p><?= nl2br(htmlspecialchars($para)) ?></p>
        <?php endforeach; ?>
    </div>
</div>
