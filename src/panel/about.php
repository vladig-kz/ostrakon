<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 Vladimir Ignatov
/**
 * "About the bot" page: public, for visitors who haven't signed in yet (what it is, why,
 * how to start a vote, how to connect it). Admin details live in help.php.
 * @var string $botUsername bot username without @ (BOT_USERNAME)
 * @var string $sourceUrl   public source code repository (SOURCE_URL)
 */
$lng  = Panel::lang();
$base = Panel::baseUrl();

// Anchor => [title key, body key, optional link [url, text]].
$sections = [
    'what'    => ['about_what_t', 'about_what_b', null],
    'why'     => ['about_why_t', 'about_why_b', null],
    'vote'    => ['about_vote_t', 'about_vote_b', null],
    'connect' => ['about_connect_t', 'about_connect_b', null],
    'data'    => ['about_data_t', 'about_data_b', [$base . '/admin/privacy', Lang::get('panel_privacy', $lng)]],
    'source'  => ['about_source_t', 'about_source_b', [$sourceUrl, $sourceUrl]],
    'history' => ['about_history_t', 'about_history_b', null],
];
?>
<a class="button is-light mb-4" href="<?= htmlspecialchars($base) ?>/admin"><?= htmlspecialchars(Lang::get('panel_to_home', $lng)) ?></a>

<h1 class="title is-3"><?= htmlspecialchars(Lang::get('panel_about', $lng)) ?></h1>

<?php foreach ($sections as $anchor => $keys): ?>
    <div class="box" id="<?= htmlspecialchars($anchor) ?>">
        <h2 class="title is-5"><?= htmlspecialchars(Lang::get($keys[0], $lng)) ?></h2>
        <p class="content"><?= htmlspecialchars(Lang::get($keys[1], $lng, ['bot' => $botUsername])) ?></p>
        <?php if ($keys[2] !== null && $keys[2][0] !== ''): ?>
            <a class="button is-small is-light" href="<?= htmlspecialchars($keys[2][0]) ?>"><?= htmlspecialchars($keys[2][1]) ?></a>
        <?php endif; ?>
    </div>
<?php endforeach; ?>
