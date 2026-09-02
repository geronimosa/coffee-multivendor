<?php
declare(strict_types=1);

require_once __DIR__ . '/env.php';
load_environment(dirname(__DIR__) . '/.env');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/auth.php';

start_secure_session();
