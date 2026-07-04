<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 Vladimir Ignatov
/**
 * Ostrakon — database connection settings (TEMPLATE).
 *
 * Copy to config/db.php and fill in the real values.
 * config/db.php holds secrets and MUST NOT be committed to version control.
 */

return [
    'DB_HOST'         => 'localhost',
    'DB_PORT'         => 3306,
    'DB_NAME'         => 'ostrakon',
    'DB_USER'         => 'ostrakon',
    'DB_PASS'         => '',
    'DB_CHARSET'      => 'utf8mb4',

    // Prefix for all tables. Applied to table names via DB::table('votes').
    // Migration .sql files use the {prefix} placeholder — the runner substitutes it.
    'DB_TABLE_PREFIX' => 'vb_',
];
