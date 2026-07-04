<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 Vladimir Ignatov
declare(strict_types=1);

/**
 * Panel — the web router and renderer of the Ostrakon admin panel.
 *
 * Routes are resolved RELATIVE to the path in APP_URL (subfolder placement supported).
 * HTML pages and JSON endpoints (/admin/api/…) are served by one router.
 * Templates live in src/panel/*.php (included, not served over HTTP).
 */
final class Panel
{
    public static function run(): void
    {
        PanelAuth::startSession();

        $route  = self::route();
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        try {
            self::dispatch($route, $method);
        } catch (Throwable $e) {
            Logger::error('Panel: unhandled error', $e, ['route' => $route]);
            self::error(500, Lang::get('panel_internal_error', self::lang()));
        }
    }

    /**
     * Panel UI language: user choice (session) → Accept-Language → first available
     * (the default language). The Telegram widget doesn't send a language.
     */
    public static function lang(): string
    {
        static $lang = null;
        if ($lang !== null) {
            return $lang;
        }
        $available = array_keys(Lang::available());
        if ($available === []) {
            return $lang = 'ru';
        }

        // A logged-in user's stored language (DB) is authoritative — it's kept in sync from both
        // the panel switcher and the bot's /language, so a change in one shows up in the other.
        $user = PanelAuth::user();
        if ($user !== null) {
            $dbLang = GroupManager::getUserLang((int) $user['id']);
            if ($dbLang !== '' && in_array($dbLang, $available, true)) {
                return $lang = $dbLang;
            }
        }

        // Anonymous (or no stored language yet): the on-site cookie choice, then Accept-Language.
        [$cookieLang] = self::parseLangCookie();
        if ($cookieLang !== '' && in_array($cookieLang, $available, true)) {
            return $lang = $cookieLang;
        }
        $header = (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
        foreach (explode(',', $header) as $part) {
            $code = strtolower(substr(trim((string) (explode(';', $part)[0] ?? '')), 0, 2));
            if ($code !== '' && in_array($code, $available, true)) {
                return $lang = $code;
            }
        }
        return $lang = $available[0];
    }

    /** Logical route without the APP_URL base path, without leading/trailing slashes. */
    private static function route(): string
    {
        $base = self::basePath();
        $uri  = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
        $uri  = rawurldecode($uri);
        if ($base !== '' && str_starts_with($uri, $base)) {
            $uri = substr($uri, strlen($base));
        }
        return trim($uri, '/');
    }

    private static function dispatch(string $route, string $method): void
    {
        if ($route === '' || $route === 'admin') {
            self::home();
            return;
        }
        if ($route === 'admin/auth') {
            PanelAuth::handleLogin();
            return;
        }
        if ($route === 'admin/logout') {
            PanelAuth::logout();
            return;
        }
        if (preg_match('#^admin/setlang/([a-zA-Z-]+)$#', $route, $m)) {
            self::setLang($m[1]);
            return;
        }
        if ($route === 'admin/help') {
            self::render('help', ['title' => Lang::get('panel_help', self::lang())]);
            return;
        }
        if (preg_match('#^admin/api/group/(-?\d+)/participants/action$#', $route, $m)) {
            self::apiParticipantAction((int) $m[1]);
            return;
        }
        if (preg_match('#^admin/api/group/(-?\d+)/participants$#', $route, $m)) {
            self::apiParticipants((int) $m[1]);
            return;
        }
        if (preg_match('#^admin/group/(-?\d+)/participants$#', $route, $m)) {
            self::participantsPage((int) $m[1]);
            return;
        }
        if (preg_match('#^admin/group/(-?\d+)/settings$#', $route, $m)) {
            if ($method === 'POST') {
                self::settingsSave((int) $m[1]);
            } else {
                self::settingsPage((int) $m[1]);
            }
            return;
        }
        if (preg_match('#^admin/group/(-?\d+)/simulator$#', $route, $m)) {
            if ($method === 'POST') {
                self::simulatorSave((int) $m[1]);
            } else {
                self::simulatorPage((int) $m[1]);
            }
            return;
        }
        if (preg_match('#^admin/api/group/(-?\d+)/votes$#', $route, $m)) {
            self::apiVotes((int) $m[1]);
            return;
        }
        if (preg_match('#^admin/group/(-?\d+)/journal$#', $route, $m)) {
            self::journalPage((int) $m[1]);
            return;
        }
        if (preg_match('#^admin/group/(-?\d+)/notifications$#', $route, $m)) {
            if ($method === 'POST') {
                self::notificationsSave((int) $m[1]);
            } else {
                self::redirect(self::baseUrl() . '/admin/group/' . (int) $m[1]);
            }
            return;
        }
        if (preg_match('#^admin/group/(-?\d+)/export$#', $route, $m)) {
            self::exportDownload((int) $m[1]);
            return;
        }
        if (preg_match('#^admin/group/(-?\d+)/migration$#', $route, $m)) {
            if ($method === 'POST') {
                self::migrationImport((int) $m[1]);
            } else {
                self::migrationPage((int) $m[1]);
            }
            return;
        }
        if (preg_match('#^admin/group/(-?\d+)$#', $route, $m)) {
            self::group((int) $m[1]);
            return;
        }
        self::error(404, Lang::get('panel_not_found', self::lang()));
    }

    /**
     * Guard for group actions: is the user logged in and an admin of THIS group. If not,
     * it sends the response itself (redirect/403/JSON) and returns null.
     *
     * @return array{id:int, name:string, username:?string}|null
     */
    private static function guardGroup(int $chatId, bool $json): ?array
    {
        $user = PanelAuth::user();
        if ($user === null) {
            if ($json) {
                self::json(['ok' => false, 'error' => 'auth'], 401);
            } else {
                self::redirectToLogin();
            }
            return null;
        }
        if (!GroupManager::isAdmin($chatId, (int) $user['id'])) {
            if ($json) {
                self::json(['ok' => false, 'error' => 'forbidden'], 403);
            } else {
                self::error(403, Lang::get('panel_not_admin', self::lang()));
            }
            return null;
        }
        return $user;
    }

    /**
     * Guard for settings/simulator: the user must be allowed to manage the group's
     * settings (owner, or an admin granted the right). Otherwise sends the response and
     * returns null.
     *
     * @return array{id:int, name:string, username:?string}|null
     */
    private static function guardManage(int $chatId, bool $json): ?array
    {
        $user = PanelAuth::user();
        if ($user === null) {
            if ($json) {
                self::json(['ok' => false, 'error' => 'auth'], 401);
            } else {
                self::redirectToLogin();
            }
            return null;
        }
        if (!GroupManager::canManage($chatId, (int) $user['id'])) {
            if ($json) {
                self::json(['ok' => false, 'error' => 'forbidden'], 403);
            } else {
                self::error(403, Lang::get('panel_not_manager', self::lang()));
            }
            return null;
        }
        return $user;
    }

    private static function home(): void
    {
        $lng  = self::lang();
        $user = PanelAuth::user();
        if ($user === null) {
            self::render('login', [
                'title'       => Lang::get('panel_login_title', $lng),
                'botUsername' => (string) Config::value('bot', 'BOT_USERNAME', ''),
                'authUrl'     => self::baseUrl() . '/admin/auth',
            ]);
            return;
        }

        $groups   = GroupManager::groupsForAdmin((int) $user['id']);
        $botUser  = (string) Config::value('bot', 'BOT_USERNAME', '');
        self::render('home', [
            'title'      => Lang::get('panel_home_title', $lng),
            'user'       => $user,
            'groups'     => $groups,
            'addToGroup' => $botUser !== '' ? ('https://t.me/' . $botUser . '?startgroup=true') : '',
        ]);
    }

    /** Landing page for a specific group. */
    private static function group(int $chatId): void
    {
        $user = self::guardGroup($chatId, false);
        if ($user === null) {
            return;
        }
        $group = GroupManager::getGroup($chatId);
        if ($group === null) {
            self::error(404, Lang::get('panel_not_found', self::lang()));
            return;
        }
        self::render('group', [
            'title'     => (string) ($group['title'] ?? ('#' . $chatId)),
            'group'     => $group,
            'canManage' => GroupManager::canManage($chatId, (int) $user['id']),
            'notify'    => GroupManager::getNotifyPrefs($chatId, (int) $user['id']),
            'csrf'      => PanelAuth::csrfToken(),
            'flash'     => self::takeFlash(),
        ]);
    }

    /** Save the current admin's personal notification preferences for a group (POST). */
    private static function notificationsSave(int $chatId): void
    {
        $user = self::guardGroup($chatId, false);
        if ($user === null) {
            return;
        }
        if (!PanelAuth::csrfCheck((string) ($_POST['csrf'] ?? ''))) {
            self::error(403, Lang::get('panel_not_admin', self::lang()));
            return;
        }
        $uid    = (int) $user['id'];
        $old    = GroupManager::getNotifyPrefs($chatId, $uid);
        $votes  = isset($_POST['notify_votes']);
        $bans   = isset($_POST['notify_bans']);
        $elders = isset($_POST['notify_elders']);
        GroupManager::setNotifyPrefs($chatId, $uid, $votes, $bans, $elders);

        // The admin came through the panel, so we know their UI language — use it for DMs.
        GroupManager::setUserLang($uid, self::lang());

        // Turned something on → check we can actually DM them (the bot can't message first).
        $newlyOn = ($votes && !$old['votes']) || ($bans && !$old['bans']) || ($elders && !$old['elders']);
        if ($newlyOn) {
            $res = Bot::call('sendMessage', ['chat_id' => $uid, 'text' => Lang::get('notify_test_dm', self::lang())]);
            if ($res === null) {
                $botUser = (string) Config::value('bot', 'BOT_USERNAME', '');
                self::setFlash(Lang::get('notify_need_dialog', self::lang(), ['bot' => $botUser]));
            } else {
                self::setFlash(Lang::get('notify_saved', self::lang()));
            }
        } else {
            self::setFlash(Lang::get('notify_saved', self::lang()));
        }
        self::redirect(self::baseUrl() . '/admin/group/' . $chatId);
    }

    /** Group "Participants" page (HTML shell + JS table). */
    private static function participantsPage(int $chatId): void
    {
        if (self::guardGroup($chatId, false) === null) {
            return;
        }
        $group = GroupManager::getGroup($chatId);
        if ($group === null) {
            self::error(404, Lang::get('panel_not_found', self::lang()));
            return;
        }
        self::render('participants', [
            'title' => (string) ($group['title'] ?? ('#' . $chatId)),
            'group' => $group,
            'csrf'  => PanelAuth::csrfToken(),
        ]);
    }

    /** Group "Settings" page (form). */
    private static function settingsPage(int $chatId): void
    {
        if (self::guardManage($chatId, false) === null) {
            return;
        }
        $group = GroupManager::getGroup($chatId);
        if ($group === null) {
            self::error(404, Lang::get('panel_not_found', self::lang()));
            return;
        }
        self::render('settings', [
            'title'  => (string) ($group['title'] ?? ('#' . $chatId)),
            'group'  => $group,
            'schema' => GroupManager::settingsSchema(),
            'csrf'   => PanelAuth::csrfToken(),
            'flash'  => self::takeFlash(),
        ]);
    }

    /** Save group settings (POST). */
    private static function settingsSave(int $chatId): void
    {
        if (self::guardManage($chatId, false) === null) {
            return;
        }
        if (!PanelAuth::csrfCheck((string) ($_POST['csrf'] ?? ''))) {
            self::error(403, Lang::get('panel_not_admin', self::lang()));
            return;
        }
        GroupManager::saveSettings($chatId, $_POST);
        self::setFlash(Lang::get('panel_settings_saved', self::lang()));
        self::redirect(self::baseUrl() . '/admin/group/' . $chatId . '/settings');
    }

    /** "Migration" page: export download + import upload (managers only). */
    private static function migrationPage(int $chatId): void
    {
        if (self::guardManage($chatId, false) === null) {
            return;
        }
        $group = GroupManager::getGroup($chatId);
        if ($group === null) {
            self::error(404, Lang::get('panel_not_found', self::lang()));
            return;
        }
        // Does the group already hold history? Used only to show the "import is additive" note.
        $hasHistory = (int) DB::fetchColumn(
            "SELECT COUNT(*) FROM " . DB::table('votes') . " WHERE chat_id = ? AND status <> 'active'",
            [$chatId]
        ) > 0;

        self::render('migration', [
            'title'      => (string) ($group['title'] ?? ('#' . $chatId)),
            'group'      => $group,
            'hasHistory' => $hasHistory,
            'csrf'       => PanelAuth::csrfToken(),
            'flash'      => self::takeFlash(),
        ]);
    }

    /** Stream the group's export as a JSON file download (managers only). */
    private static function exportDownload(int $chatId): void
    {
        if (self::guardManage($chatId, false) === null) {
            return;
        }
        if (GroupManager::getGroup($chatId) === null) {
            self::error(404, Lang::get('panel_not_found', self::lang()));
            return;
        }
        $data = Exporter::export($chatId);
        $name = 'ostrakon-' . $chatId . '-' . gmdate('Ymd-His') . '.json';
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /** Handle an uploaded export file (POST, managers only). */
    private static function migrationImport(int $chatId): void
    {
        if (self::guardManage($chatId, false) === null) {
            return;
        }
        if (!PanelAuth::csrfCheck((string) ($_POST['csrf'] ?? ''))) {
            self::error(403, Lang::get('panel_not_manager', self::lang()));
            return;
        }
        $back = self::baseUrl() . '/admin/group/' . $chatId . '/migration';

        $file = $_FILES['import_file'] ?? null;
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            self::setFlash(Lang::get('panel_import_bad_file', self::lang()));
            self::redirect($back);
            return;
        }
        $raw  = (string) file_get_contents((string) $file['tmp_name']);
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            self::setFlash(Lang::get('panel_import_bad_file', self::lang()));
            self::redirect($back);
            return;
        }

        try {
            $counts = Exporter::import($chatId, $json);
            self::setFlash(Lang::get('panel_import_done', self::lang(), [
                'participants'  => (string) $counts['participants'],
                'messages'      => (string) $counts['messages'],
                'votes'         => (string) $counts['votes'],
                'votes_skipped' => (string) $counts['votes_skipped'],
            ]));
        } catch (Throwable $e) {
            // Exporter throws a lang-key code for the expected validation failures.
            $key = in_array($e->getMessage(), ['import_bad_file', 'import_bad_version', 'import_wrong_group'], true)
                ? 'panel_' . $e->getMessage()
                : 'panel_import_failed';
            self::setFlash(Lang::get($key, self::lang()));
        }
        self::redirect($back);
    }

    /** JSON: participants list (search/sort/paginate) for the JS table. */
    private static function apiParticipants(int $chatId): void
    {
        $user = self::guardGroup($chatId, true);
        if ($user === null) {
            return;
        }
        $perPage = 25;
        $page    = (int) ($_GET['page'] ?? 1);

        // Live status: one getChatAdministrators call per page. We distinguish the owner
        // (creator) from admins and show an admin's custom title (custom_title is exposed
        // by the API ONLY for admins — regular members have no such field). We also seed a
        // participant row for every admin BEFORE listing, so admins who have never posted
        // still show up in the list (and can be granted the "manager" right). Otherwise
        // they'd be invisible here until they happened to trigger a row on their own.
        $adminInfo = [];
        $admins = Bot::call('getChatAdministrators', ['chat_id' => $chatId]);
        if (is_array($admins)) {
            foreach ($admins as $a) {
                $uid = (int) ($a['user']['id'] ?? 0);
                if ($uid !== 0) {
                    $adminInfo[$uid] = [
                        'status' => (string) ($a['status'] ?? ''),
                        'title'  => (string) ($a['custom_title'] ?? ''),
                    ];
                    GroupManager::ensureParticipant($chatId, $uid, $a['user']['username'] ?? null);
                }
            }
        }

        $res = GroupManager::listParticipants(
            $chatId,
            trim((string) ($_GET['q'] ?? '')),
            (string) ($_GET['sort'] ?? ''),
            $page,
            $perPage
        );

        foreach ($res['rows'] as &$row) {
            $info = $adminInfo[(int) $row['user_id']] ?? null;
            $row['is_admin']    = $info ? 1 : 0;
            $row['is_owner']    = ($info && $info['status'] === 'creator') ? 1 : 0;
            $row['admin_title'] = $info ? $info['title'] : '';
            $row['can_manage']  = (int) ($row['can_manage'] ?? 0);
            $row['is_elder']    = (int) ($row['is_elder'] ?? 0);
        }
        unset($row);

        // Only the owner can grant/revoke the "manager" right (the UI shows the buttons then).
        $viewerIsOwner = (($adminInfo[(int) $user['id']]['status'] ?? '') === 'creator');

        self::json([
            'ok'            => true,
            'rows'          => $res['rows'],
            'total'         => $res['total'],
            'page'          => max(1, $page),
            'perPage'       => $perPage,
            'pages'         => (int) max(1, ceil($res['total'] / $perPage)),
            'viewerIsOwner' => $viewerIsOwner,
        ]);
    }

    /** "Elder simulator" page (full mode only). */
    private static function simulatorPage(int $chatId): void
    {
        if (self::guardManage($chatId, false) === null) {
            return;
        }
        $group = GroupManager::getGroup($chatId);
        if ($group === null) {
            self::error(404, Lang::get('panel_not_found', self::lang()));
            return;
        }

        // Exclude admins from the calculation (they don't need elder status — they ban instantly).
        $adminIds = [];
        $admins = Bot::call('getChatAdministrators', ['chat_id' => $chatId]);
        if (is_array($admins)) {
            foreach ($admins as $a) {
                if (isset($a['user']['id'])) {
                    $adminIds[] = (int) $a['user']['id'];
                }
            }
        }

        self::render('simulator', [
            'title' => (string) ($group['title'] ?? ('#' . $chatId)),
            'group' => $group,
            'stats' => ScoreManager::activityStats($chatId, $adminIds),
            'csrf'  => PanelAuth::csrfToken(),
            'flash' => self::takeFlash(),
        ]);
    }

    /** Save the elder parameters from the simulator (POST). */
    private static function simulatorSave(int $chatId): void
    {
        if (self::guardManage($chatId, false) === null) {
            return;
        }
        if (!PanelAuth::csrfCheck((string) ($_POST['csrf'] ?? ''))) {
            self::error(403, Lang::get('panel_not_admin', self::lang()));
            return;
        }
        GroupManager::saveElderParams(
            $chatId,
            (float) ($_POST['halflife_days'] ?? 0),
            (float) str_replace(',', '.', (string) ($_POST['elder_threshold'] ?? 0))
        );
        self::setFlash(Lang::get('panel_settings_saved', self::lang()));
        self::redirect(self::baseUrl() . '/admin/group/' . $chatId . '/simulator');
    }

    /** "Vote journal" page (HTML shell + JS table). */
    private static function journalPage(int $chatId): void
    {
        if (self::guardGroup($chatId, false) === null) {
            return;
        }
        $group = GroupManager::getGroup($chatId);
        if ($group === null) {
            self::error(404, Lang::get('panel_not_found', self::lang()));
            return;
        }
        self::render('journal', [
            'title' => (string) ($group['title'] ?? ('#' . $chatId)),
            'group' => $group,
            'csrf'  => PanelAuth::csrfToken(),
        ]);
    }

    /** JSON: vote journal (search/status/sort/paginate). */
    private static function apiVotes(int $chatId): void
    {
        if (self::guardGroup($chatId, true) === null) {
            return;
        }
        $perPage = 25;
        $page    = (int) ($_GET['page'] ?? 1);
        $res = GroupManager::listVotes(
            $chatId,
            trim((string) ($_GET['q'] ?? '')),
            (string) ($_GET['sort'] ?? ''),
            $page,
            $perPage,
            (string) ($_GET['status'] ?? '')
        );
        self::json([
            'ok'      => true,
            'rows'    => $res['rows'],
            'total'   => $res['total'],
            'page'    => max(1, $page),
            'perPage' => $perPage,
            'pages'   => (int) max(1, ceil($res['total'] / $perPage)),
        ]);
    }

    /** JSON (POST): a participant action — protect/unprotect/unban. */
    private static function apiParticipantAction(int $chatId): void
    {
        $user = self::guardGroup($chatId, true);
        if ($user === null) {
            return;
        }
        if (!PanelAuth::csrfCheck((string) ($_POST['csrf'] ?? ''))) {
            self::json(['ok' => false, 'error' => 'csrf'], 403);
            return;
        }
        $userId = (int) ($_POST['user_id'] ?? 0);
        $action = (string) ($_POST['action'] ?? '');
        if ($userId === 0) {
            self::json(['ok' => false, 'error' => 'no_user'], 400);
            return;
        }
        switch ($action) {
            case 'protect':
                GroupManager::setProtectionById($chatId, $userId, true);
                break;
            case 'unprotect':
                GroupManager::setProtectionById($chatId, $userId, false);
                break;
            case 'unban':
                GroupManager::unbanById($chatId, $userId);
                break;
            case 'make_elder':
                // Any admin; full mode only. Backfills a fake history so the score hits the
                // threshold now (no undo — it then decays with real activity).
                $res = ScoreManager::appointElder($chatId, $userId);
                if ($res !== 'ok' && $res !== 'already') {
                    self::json(['ok' => false, 'error' => $res], 400);
                    return;
                }
                break;
            case 'grant_manage':
            case 'revoke_manage':
                // Only the owner can grant/revoke, and only to admins.
                if (!GroupManager::isOwner($chatId, (int) $user['id'])) {
                    self::json(['ok' => false, 'error' => 'forbidden'], 403);
                    return;
                }
                if (!GroupManager::isAdmin($chatId, $userId)) {
                    self::json(['ok' => false, 'error' => 'not_admin'], 400);
                    return;
                }
                GroupManager::setManager($chatId, $userId, $action === 'grant_manage');
                break;
            default:
                self::json(['ok' => false, 'error' => 'bad_action'], 400);
                return;
        }
        Logger::info('Panel: participant action', [
            'chat_id' => $chatId,
            'user'    => $userId,
            'action'  => $action,
            'by'      => $user['id'],
        ]);
        self::json(['ok' => true]);
    }

    private static function redirect(string $url): void
    {
        header('Location: ' . $url);
        echo 'Redirecting…';
    }

    /**
     * Not logged in on a page route: remember where the user was headed (so login can return
     * them there), then go to the login page. The destination itself re-checks admin rights,
     * so a hand-crafted link to a foreign group still yields 403 after login.
     */
    private static function redirectToLogin(): void
    {
        $route = self::route();
        if (str_starts_with($route, 'admin')) {
            $_SESSION['after_login'] = $route;
        }
        self::redirect(self::baseUrl() . '/admin');
    }

    /** Internal route to return to after login (validated, consumed once). '' if none. */
    public static function takeAfterLogin(): string
    {
        $next = $_SESSION['after_login'] ?? null;
        unset($_SESSION['after_login']);
        // Only our own routes; never an absolute URL or external host.
        if (is_string($next) && str_starts_with($next, 'admin') && !str_contains($next, '://')) {
            return $next;
        }
        return '';
    }

    /** Set the panel UI language: record the choice (cookie + DB if logged in), then return back. */
    private static function setLang(string $code): void
    {
        if (array_key_exists($code, Lang::available())) {
            self::writeLangCookie($code);
            // Logged in → the DB is the source of truth; store the choice there too (so it also
            // reaches the bot DM, and survives a login from another browser).
            $user = PanelAuth::user();
            if ($user !== null) {
                GroupManager::setUserLang((int) $user['id'], $code);
            }
        }
        // Return to where the user came from, if it's inside our panel; otherwise home.
        $ref  = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        $base = self::baseUrl();
        self::redirect(($ref !== '' && str_starts_with($ref, $base)) ? $ref : $base . '/admin');
    }

    /** Write the language cookie as "code|<DB-time of the change>" (1-year lifetime). */
    private static function writeLangCookie(string $code): void
    {
        $base = self::basePath();
        // The change timestamp comes from the DB server (single source of time), so it's directly
        // comparable with users.updated_at at login. The cookie EXPIRY is browser-side, so PHP
        // time() is fine there.
        setcookie('ostrakon_lang', $code . '|' . DB::nowUnix(), [
            'expires'  => time() + 31536000,
            'path'     => $base !== '' ? $base : '/',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /** Parse the ostrakon_lang cookie into [code, changedAtUnix]. Old cookies without a time → 0. */
    private static function parseLangCookie(): array
    {
        $raw = (string) ($_COOKIE['ostrakon_lang'] ?? '');
        if ($raw === '') {
            return ['', 0];
        }
        $parts = explode('|', $raw, 2);
        return [(string) $parts[0], (int) ($parts[1] ?? 0)];
    }

    /**
     * At login, reconcile the on-site language cookie with the user's stored language — the more
     * recently changed one wins. If the cookie (an explicit on-site choice) is newer, the DB
     * adopts it; otherwise the stored value stays and lang() uses it. Called once, after login.
     */
    public static function reconcileLoginLanguage(int $userId): void
    {
        [$cookieLang, $cookieTs] = self::parseLangCookie();
        if ($cookieLang === '' || !array_key_exists($cookieLang, Lang::available())) {
            return; // no valid on-site choice to carry over
        }
        $meta = GroupManager::getUserLangMeta($userId);
        if ($meta['lang'] === '' || $cookieTs > $meta['ts']) {
            GroupManager::setUserLang($userId, $cookieLang);
        }
    }

    /** One-shot message between requests (PRG: shown after a redirect). */
    private static function setFlash(string $msg): void
    {
        $_SESSION['panel_flash'] = $msg;
    }

    private static function takeFlash(): ?string
    {
        $msg = $_SESSION['panel_flash'] ?? null;
        unset($_SESSION['panel_flash']);
        return is_string($msg) ? $msg : null;
    }

    // ------------------------------------------------------------------
    // Rendering
    // ------------------------------------------------------------------

    /** @param array<string, mixed> $vars */
    public static function render(string $view, array $vars = []): void
    {
        $title = (string) ($vars['title'] ?? 'Ostrakon');
        extract($vars, EXTR_SKIP);

        ob_start();
        require OSTRAKON_ROOT . '/src/panel/' . $view . '.php';
        $content = ob_get_clean();

        require OSTRAKON_ROOT . '/src/panel/layout.php';
    }

    public static function error(int $code, string $msg): void
    {
        http_response_code($code);
        self::render('error', [
            'title' => Lang::get('panel_error_title', self::lang(), ['code' => $code]),
            'code'  => $code,
            'msg'   => $msg,
        ]);
    }

    /** JSON response for API routes. @param array<string, mixed> $data */
    public static function json(array $data, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    // ------------------------------------------------------------------
    // Paths (from APP_URL)
    // ------------------------------------------------------------------

    /** Full base URL of the app without a trailing slash. */
    public static function baseUrl(): string
    {
        return rtrim((string) Config::value('bot', 'APP_URL', ''), '/');
    }

    /** The path part of APP_URL without a trailing slash ('' for domain root, '/ostrakon' for a subfolder). */
    public static function basePath(): string
    {
        return rtrim((string) (parse_url(self::baseUrl(), PHP_URL_PATH) ?: ''), '/');
    }
}
