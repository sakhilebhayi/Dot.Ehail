<?php

namespace App\Domain\Comms\Push;

use RuntimeException;

/**
 * Message encryption for Web Push, RFC 8291 with the aes128gcm content coding of RFC 8188: one
 * record, a fresh ephemeral key and salt per message. Verified against the RFC 8291 Appendix A
 * test vector (tests/Unit/Comms/PayloadEncrypterTest).
 */
final class PayloadEncrypter
{
    public const RECORD_SIZE = 4096;

    /** Largest plaintext that fits one record (4096 - 16 tag - 1 delimiter - 86 header, rounded down). */
    public const MAX_PLAINTEXT = 3993;

    /**
     * @param  string  $uaPublic  the subscription's p256dh key (65 raw bytes)
     * @param  string  $authSecret  the subscription's auth secret (16 raw bytes)
     * @param  array{private: string, public: string}|null  $ephemeral  only for the test vector
     */
    public function encrypt(string $plaintext, string $uaPublic, string $authSecret, ?array $ephemeral = null, ?string $salt = null): string
    {
        if (strlen($plaintext) > self::MAX_PLAINTEXT) {
            throw new RuntimeException('Push payload too large.');
        }

        if (strlen($authSecret) !== 16) {
            throw new RuntimeException('Bad auth secret.');
        }

        $ephemeral ??= EcKeys::generate();
        $salt ??= random_bytes(16);

        $shared = EcKeys::sharedSecret(EcKeys::privateKey($ephemeral['private'], $ephemeral['public']), EcKeys::publicKey($uaPublic));

        // RFC 8291 section 3.4
        $prkKey = hash_hmac('sha256', $shared, $authSecret, true);
        $keyInfo = "WebPush: info\0".$uaPublic.$ephemeral['public'];
        $ikm = hash_hmac('sha256', $keyInfo."\x01", $prkKey, true);

        // RFC 8188 section 2.2
        $prk = hash_hmac('sha256', $ikm, $salt, true);
        $cek = substr(hash_hmac('sha256', "Content-Encoding: aes128gcm\0\x01", $prk, true), 0, 16);
        $nonce = substr(hash_hmac('sha256', "Content-Encoding: nonce\0\x01", $prk, true), 0, 12);

        $tag = '';
        $cipher = openssl_encrypt($plaintext."\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);

        if ($cipher === false) {
            throw new RuntimeException('Encryption failed.');
        }

        return $salt.pack('N', self::RECORD_SIZE).chr(65).$ephemeral['public'].$cipher.$tag;
    }

    /** The receiving side, for tests: decrypts what encrypt() produced. */
    public function decrypt(string $body, string $uaPrivate, string $uaPublic, string $authSecret): string
    {
        $salt = substr($body, 0, 16);
        $idLength = ord($body[20]);
        $asPublic = substr($body, 21, $idLength);
        $rest = substr($body, 21 + $idLength);
        $cipher = substr($rest, 0, -16);
        $tag = substr($rest, -16);

        $shared = EcKeys::sharedSecret(EcKeys::privateKey($uaPrivate, $uaPublic), EcKeys::publicKey($asPublic));
        $prkKey = hash_hmac('sha256', $shared, $authSecret, true);
        $ikm = hash_hmac('sha256', "WebPush: info\0".$uaPublic.$asPublic."\x01", $prkKey, true);
        $prk = hash_hmac('sha256', $ikm, $salt, true);
        $cek = substr(hash_hmac('sha256', "Content-Encoding: aes128gcm\0\x01", $prk, true), 0, 16);
        $nonce = substr(hash_hmac('sha256', "Content-Encoding: nonce\0\x01", $prk, true), 0, 12);

        $plain = openssl_decrypt($cipher, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);

        if ($plain === false) {
            throw new RuntimeException('Decryption failed.');
        }

        return substr($plain, 0, (int) strrpos($plain, "\x02"));
    }
}
