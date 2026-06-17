<?php
// PlanBär Configuration
// WARNING: Keep this file outside web root in production!
// DB credentials
define('DB_HOST', 'localhost');
define('DB_NAME', 'planbear');
define('DB_USER', 'planbear_user');
define('DB_PASS', 'change_me_strong_password');
define('DB_CHARSET', 'utf8mb4');

// AES-256 key as hex (64 hex chars = 32 bytes). CHANGE THIS before going live!
define('CRYPTO_KEY_HEX', 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0c1d2e3f4a5b6c7d8e9f0a1b2');

define('APP_NAME', 'PlanBär');
define('APP_VERSION', '1.0.0');
define('SESSION_TIMEOUT', 28800); // 8 hours in seconds

// Error display — set to 1 only during development
define('DEBUG_MODE', 0);
