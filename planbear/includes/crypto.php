<?php
// Encryption helpers — AES-256-CBC
// Requires config.php to have been included (defines CRYPTO_KEY_HEX)

if (!defined('ENCRYPTION_KEY')) {
    define('ENCRYPTION_KEY', hex2bin(CRYPTO_KEY_HEX));
}

/**
 * Encrypt a string with AES-256-CBC.
 * Returns base64-encoded IV + ciphertext.
 */
function encrypt(string $data): string {
    $iv = random_bytes(16);
    $cipher = openssl_encrypt($data, 'AES-256-CBC', ENCRYPTION_KEY, OPENSSL_RAW_DATA, $iv);
    if ($cipher === false) {
        throw new RuntimeException('Encryption failed: ' . openssl_error_string());
    }
    return base64_encode($iv . $cipher);
}

/**
 * Decrypt a base64-encoded AES-256-CBC ciphertext (IV prepended).
 * Returns the original plaintext string.
 */
function decrypt(string $data): string {
    $raw = base64_decode($data, true);
    if ($raw === false || strlen($raw) < 17) {
        return ''; // Corrupted data — return empty rather than crash
    }
    $iv = substr($raw, 0, 16);
    $cipher = substr($raw, 16);
    $plain = openssl_decrypt($cipher, 'AES-256-CBC', ENCRYPTION_KEY, OPENSSL_RAW_DATA, $iv);
    return ($plain === false) ? '' : $plain;
}
