<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 Vladimir Ignatov
/**
 * Login page: the Telegram Login Widget button.
 * @var string $botUsername bot username without @ (BOT_USERNAME)
 * @var string $authUrl     widget data endpoint URL (APP_URL/admin/auth)
 */
$lng = Panel::lang();
?>
<div class="box has-text-centered mx-auto" style="max-width:460px;">
    <h1 class="title is-4"><?= htmlspecialchars(Lang::get('panel_login_title', $lng)) ?></h1>
    <p class="subtitle is-6 has-text-grey"><?= htmlspecialchars(Lang::get('panel_login_hint', $lng)) ?></p>

    <?php if ($botUsername === ''): ?>
        <p class="notification is-danger is-light"><?= htmlspecialchars(Lang::get('panel_no_botusername', $lng)) ?></p>
    <?php else: ?>
        <div class="tg-login">
            <script async src="https://telegram.org/js/telegram-widget.js?22"
                    data-telegram-login="<?= htmlspecialchars($botUsername) ?>"
                    data-size="large"
                    data-userpic="true"
                    data-auth-url="<?= htmlspecialchars($authUrl) ?>"
                    data-request-access="write"></script>
        </div>
    <?php endif; ?>
</div>
