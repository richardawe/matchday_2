<?php

namespace Tests\Unit;

use App\Models\PushSubscription;
use App\Services\Alerts\WebPush;
use Tests\TestCase;

class WebPushTest extends TestCase
{
    /** RFC 8291 section 5: the worked example, byte for byte. */
    public function test_encryption_matches_the_rfc_example(): void
    {
        $body = app(WebPush::class)->encrypt(
            'When I grow up, I want to be a watermelon',
            'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4',
            'BTBZMqHH6r4Tts7J_aSIgg',
            localPrivate: 'yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw',
            salt: 'DGv6ra1nlYgDCS1FRnbzlw',
        );

        $this->assertSame('DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPTpK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN', WebPush::b64($body));
    }

    public function test_a_browser_can_decrypt_what_it_is_sent(): void
    {
        $browser = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $ec = openssl_pkey_get_details($browser)['ec'];
        $uaPublic = "\x04".str_pad($ec['x'], 32, "\0", STR_PAD_LEFT).str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);
        $auth = random_bytes(16);

        $body = app(WebPush::class)->encrypt('{"title":"GOAL!"}', WebPush::b64($uaPublic), WebPush::b64($auth));

        // The receiving side of RFC 8291.
        $salt = substr($body, 0, 16);
        $asPublic = substr($body, 21, 65);
        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode(hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200').$asPublic), 64, "\n")."-----END PUBLIC KEY-----\n";
        $shared = openssl_pkey_derive($pem, $browser, 32);
        $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0".$uaPublic.$asPublic, $auth);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
        $cipher = substr($body, 86, -16);
        $plain = openssl_decrypt($cipher, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, substr($body, -16));

        $this->assertSame("{\"title\":\"GOAL!\"}\x02", $plain);
    }

    public function test_vapid_token_verifies_with_the_public_key(): void
    {
        $keys = WebPush::createKeys();
        config(['push.vapid' => $keys + ['subject' => 'mailto:hello@matchday.africa']]);

        $header = app(WebPush::class)->vapid('https://fcm.googleapis.com/fcm/send/abc');

        $this->assertMatchesRegularExpression('/^vapid t=[\w-]+\.[\w-]+\.[\w-]+, k='.preg_quote($keys['public_key'], '/').'$/', $header);
        [$h, $c, $s] = explode('.', substr($header, 8, strpos($header, ',') - 8));
        $this->assertSame('https://fcm.googleapis.com', json_decode(WebPush::unb64($c), true)['aud']);
        // Back to DER to check the signature with OpenSSL.
        $raw = WebPush::unb64($s);
        $int = fn ($b) => (ord(ltrim($b, "\0")[0] ?? "\0") & 0x80 ? "\0" : '').ltrim($b, "\0");
        [$r, $q] = [$int(substr($raw, 0, 32)), $int(substr($raw, 32))];
        $der = "\x30".chr(4 + strlen($r) + strlen($q))."\x02".chr(strlen($r)).$r."\x02".chr(strlen($q)).$q;
        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode(hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200').WebPush::unb64($keys['public_key'])), 64, "\n")."-----END PUBLIC KEY-----\n";
        $this->assertSame(1, openssl_verify("{$h}.{$c}", $der, $pem, OPENSSL_ALGO_SHA256));
    }
}
