<?php

namespace Tests\Unit\Comms;

use App\Domain\Comms\Push\Base64Url;
use App\Domain\Comms\Push\EcKeys;
use App\Domain\Comms\Push\PayloadEncrypter;
use PHPUnit\Framework\TestCase;

/** RFC 8291 Appendix A: the published test vector, byte for byte. */
class PayloadEncrypterTest extends TestCase
{
    private const PLAINTEXT = 'When I grow up, I want to be a watermelon';

    private const AS_PRIVATE = 'yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw';

    private const AS_PUBLIC = 'BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8';

    private const UA_PRIVATE = 'q1dXpw3UpT5VOmu_cf_v6ih07Aems3njxI-JWgLcM94';

    private const UA_PUBLIC = 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4';

    private const SALT = 'DGv6ra1nlYgDCS1FRnbzlw';

    private const AUTH = 'BTBZMqHH6r4Tts7J_aSIgg';

    private const EXPECTED = 'DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPTpK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN';

    public function test_the_rfc_8291_test_vector_is_reproduced_exactly(): void
    {
        $body = (new PayloadEncrypter)->encrypt(
            self::PLAINTEXT,
            Base64Url::decode(self::UA_PUBLIC),
            Base64Url::decode(self::AUTH),
            ['private' => Base64Url::decode(self::AS_PRIVATE), 'public' => Base64Url::decode(self::AS_PUBLIC)],
            Base64Url::decode(self::SALT),
        );

        $this->assertSame(self::EXPECTED, Base64Url::encode($body));
    }

    public function test_the_receiver_can_decrypt_and_every_message_uses_a_fresh_key_and_salt(): void
    {
        $encrypter = new PayloadEncrypter;
        $ua = EcKeys::generate();
        $auth = random_bytes(16);

        $one = $encrypter->encrypt('{"title":"Hi"}', $ua['public'], $auth);
        $two = $encrypter->encrypt('{"title":"Hi"}', $ua['public'], $auth);

        $this->assertNotSame($one, $two);
        $this->assertNotSame(substr($one, 0, 16), substr($two, 0, 16), 'salt differs');
        $this->assertSame('{"title":"Hi"}', $encrypter->decrypt($one, $ua['private'], $ua['public'], $auth));
        $this->assertSame('{"title":"Hi"}', $encrypter->decrypt($two, $ua['private'], $ua['public'], $auth));
    }

    public function test_es256_signatures_are_64_raw_bytes_that_verify(): void
    {
        $keys = EcKeys::generate();
        $signature = EcKeys::signEs256('header.claims', EcKeys::privateKey($keys['private'], $keys['public']));

        $this->assertSame(64, strlen($signature));
        $this->assertSame(1, openssl_verify('header.claims', self::der($signature), EcKeys::publicKey($keys['public']), OPENSSL_ALGO_SHA256));
    }

    /** Raw R||S back to the DER that openssl_verify expects. */
    public static function der(string $raw): string
    {
        $int = function (string $part): string {
            $part = ltrim($part, "\0");
            if ($part === '' || ord($part[0]) > 0x7f) {
                $part = "\0".$part;
            }

            return "\x02".chr(strlen($part)).$part;
        };
        $body = $int(substr($raw, 0, 32)).$int(substr($raw, 32));

        return "\x30".chr(strlen($body)).$body;
    }
}
