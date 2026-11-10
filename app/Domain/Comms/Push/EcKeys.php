<?php

namespace App\Domain\Comms\Push;

use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * P-256 key handling with PHP's openssl extension only (decision D-1301: no web-push library).
 * Raw keys are what Web Push uses on the wire: a public key is the 65-byte uncompressed point
 * (0x04 || X || Y), a private key the 32-byte scalar d.
 */
final class EcKeys
{
    /** DER prefix of a P-256 SubjectPublicKeyInfo, followed by the 65-byte point. */
    private const SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    /** @return array{private: string, public: string} raw d (32 bytes) and point (65 bytes) */
    public static function generate(): array
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);

        if ($key === false) {
            throw new RuntimeException('openssl could not create a P-256 key.');
        }

        $ec = openssl_pkey_get_details($key)['ec'];

        return [
            'private' => str_pad($ec['d'], 32, "\0", STR_PAD_LEFT),
            'public' => "\x04".str_pad($ec['x'], 32, "\0", STR_PAD_LEFT).str_pad($ec['y'], 32, "\0", STR_PAD_LEFT),
        ];
    }

    public static function publicKey(string $point): OpenSSLAsymmetricKey
    {
        if (strlen($point) !== 65 || $point[0] !== "\x04") {
            throw new RuntimeException('Not an uncompressed P-256 point.');
        }

        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode(hex2bin(self::SPKI_PREFIX).$point), 64, "\n")."-----END PUBLIC KEY-----\n";
        $key = openssl_pkey_get_public($pem);

        if ($key === false) {
            throw new RuntimeException('openssl refused the public key.');
        }

        return $key;
    }

    public static function privateKey(string $d, string $point): OpenSSLAsymmetricKey
    {
        if (strlen($d) !== 32 || strlen($point) !== 65) {
            throw new RuntimeException('Bad P-256 private key.');
        }

        // SEC1 ECPrivateKey: version 1, d, curve OID (prime256v1), public point.
        $der = "\x30\x77\x02\x01\x01\x04\x20".$d
            ."\xa0\x0a\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07"
            ."\xa1\x44\x03\x42\x00".$point;
        $pem = "-----BEGIN EC PRIVATE KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END EC PRIVATE KEY-----\n";
        $key = openssl_pkey_get_private($pem);

        if ($key === false) {
            throw new RuntimeException('openssl refused the private key.');
        }

        return $key;
    }

    /** ECDH shared secret (32 bytes). */
    public static function sharedSecret(OpenSSLAsymmetricKey $private, OpenSSLAsymmetricKey $peerPublic): string
    {
        $secret = openssl_pkey_derive($peerPublic, $private);

        if ($secret === false) {
            throw new RuntimeException('ECDH failed.');
        }

        return str_pad($secret, 32, "\0", STR_PAD_LEFT);
    }

    /** ES256 signature as JOSE wants it: R || S, 64 bytes (openssl gives DER). */
    public static function signEs256(string $data, OpenSSLAsymmetricKey $private): string
    {
        if (! openssl_sign($data, $der, $private, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Signing failed.');
        }

        $offset = 2;
        if ((ord($der[1]) & 0x80) !== 0) {
            $offset += ord($der[1]) & 0x7f;
        }

        $parts = [];
        for ($i = 0; $i < 2; $i++) {
            $length = ord($der[$offset + 1]);
            $value = substr($der, $offset + 2, $length);
            $parts[] = str_pad(ltrim($value, "\0"), 32, "\0", STR_PAD_LEFT);
            $offset += 2 + $length;
        }

        return $parts[0].$parts[1];
    }
}
