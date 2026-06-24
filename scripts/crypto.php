<?php

// Authenticated encryption using libsodium secretbox.
// Ciphertext format stored in DB: "enc:" + base64(nonce . ciphertext)
// The "enc:" prefix lets callers distinguish encrypted values from pre-migration plaintext.

function encrypt(string $plain): string {
    $key   = base64_decode($_ENV['APP_ENCRYPTION_KEY']);
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return 'enc:' . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $key));
}

// Returns the plaintext string, or false if $encoded is not an "enc:" value or auth fails.
function decrypt(string $encoded) {
    if (strncmp($encoded, 'enc:', 4) !== 0) return false;
    $key     = base64_decode($_ENV['APP_ENCRYPTION_KEY']);
    $raw     = base64_decode(substr($encoded, 4));
    if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) return false;
    $nonce   = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipher  = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return sodium_crypto_secretbox_open($cipher, $nonce, $key);
}
