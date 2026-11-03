<?php

namespace App\Services\Alerts;

use App\Models\PushSubscription;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Web push without extra PHP extensions: message encryption (RFC 8291, aes128gcm per RFC 8188)
 * and VAPID sender identification (RFC 8292), using only OpenSSL. The usual library needs gmp
 * or bcmath, which shared hosts don't always have.
 */
class WebPush
{
    /** DER prefix of a P-256 public key (SubjectPublicKeyInfo) before the 65 raw bytes. */
    private const SPKI = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    public function configured(): bool
    {
        return config('push.enabled') && config('push.vapid.public_key') && config('push.vapid.private_key');
    }

    /** A new VAPID key pair, base64url: ['public_key' => 65 bytes, 'private_key' => 32 bytes]. */
    public static function createKeys(): array
    {
        [$private, $public] = self::newKey();

        return ['public_key' => self::b64(self::rawPublic($public)), 'private_key' => self::b64(self::rawPrivate($private))];
    }

    /**
     * Sends one payload to many subscriptions, 50 at a time. Subscriptions the push service says
     * are gone (404/410) are deleted; others that keep failing are dropped after max_failures.
     *
     * @param Collection<int, PushSubscription> $subscriptions
     * @return array{sent: int, failed: int, removed: int}
     */
    public function send(Collection $subscriptions, array $payload, ?string $topic = null): array
    {
        $result = ['sent' => 0, 'failed' => 0, 'removed' => 0];
        if (!$this->configured() || $subscriptions->isEmpty()) {
            return $result;
        }
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        foreach ($subscriptions->chunk(50) as $chunk) {
            $requests = [];
            foreach ($chunk as $sub) {
                try {
                    $requests[$sub->id] = [$sub, $this->request($sub, $json, $topic)];
                } catch (Throwable $e) {
                    Log::warning('Push message could not be prepared', ['subscription' => $sub->id, 'error' => $e->getMessage()]);
                    $result['failed']++;
                }
            }
            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn ($pair) => $pool->as((string) $pair[0]->id)->timeout(10)->withHeaders($pair[1]['headers'])
                    ->withBody($pair[1]['body'], 'application/octet-stream')->post($pair[0]->endpoint),
                $requests,
            ));
            foreach ($requests as $id => [$sub]) {
                $response = $responses[(string) $id] ?? null;
                $status = $response instanceof \Illuminate\Http\Client\Response ? $response->status() : 0;
                if ($status >= 200 && $status < 300) {
                    $sub->forceFill(['failures' => 0, 'last_sent_at' => now()])->save();
                    $result['sent']++;
                } elseif ($status === 404 || $status === 410 || $sub->failures + 1 >= config('push.max_failures')) {
                    $sub->delete();
                    $result['removed']++;
                } else {
                    $sub->increment('failures');
                    $result['failed']++;
                    Log::info('Push service refused a message', ['subscription' => $id, 'status' => $status, 'body' => $response instanceof \Illuminate\Http\Client\Response ? mb_substr($response->body(), 0, 200) : null]);
                }
            }
        }

        return $result;
    }

    /** Headers and encrypted body for one subscription. */
    public function request(PushSubscription $sub, string $json, ?string $topic = null): array
    {
        $headers = [
            'Content-Encoding' => 'aes128gcm',
            'TTL' => (string) config('push.ttl'),
            'Urgency' => 'high',
            'Authorization' => $this->vapid($sub->endpoint),
        ];
        if ($topic) {
            // A newer message with the same topic replaces one still waiting on the push service.
            $headers['Topic'] = substr(preg_replace('/[^A-Za-z0-9_-]/', '', $topic), 0, 32);
        }

        return ['headers' => $headers, 'body' => $this->encrypt($json, $sub->p256dh, $sub->auth)];
    }

    /**
     * RFC 8291: encrypts a message for a browser's key (p256dh) and auth secret, both base64url.
     * $localPrivate (base64url d) and $salt exist for the RFC's test vector; leave them null.
     */
    public function encrypt(string $plaintext, string $p256dh, string $auth, ?string $localPrivate = null, ?string $salt = null): string
    {
        $uaPublic = self::unb64($p256dh);
        $secret = self::unb64($auth);
        if (strlen($uaPublic) !== 65 || $uaPublic[0] !== "\x04" || strlen($secret) !== 16) {
            throw new RuntimeException('Invalid subscription keys');
        }
        if ($localPrivate) {
            $d = self::unb64($localPrivate);
            $local = openssl_pkey_get_private(self::privatePem($d, self::publicFromPrivate($d)));
        } else {
            [$local] = self::newKey();
        }
        $asPublic = self::rawPublic($local);
        $shared = openssl_pkey_derive(self::publicPem($uaPublic), $local, 32);
        if ($shared === false) {
            throw new RuntimeException('Key agreement failed');
        }
        $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0".$uaPublic.$asPublic, $secret);
        $salt = $salt ? self::unb64($salt) : random_bytes(16);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
        // One record: the message, then the 0x02 delimiter that marks the last record.
        $cipher = openssl_encrypt($plaintext."\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);

        return $salt.pack('N', 4096).chr(65).$asPublic.$cipher.$tag;
    }

    /** RFC 8292 Authorization header: a 12-hour ES256 token for the push service's origin. */
    public function vapid(string $endpoint): string
    {
        $parts = parse_url($endpoint);
        $claims = ['aud' => "{$parts['scheme']}://{$parts['host']}".(isset($parts['port']) ? ":{$parts['port']}" : ''), 'exp' => time() + 12 * 3600, 'sub' => config('push.vapid.subject')];
        $input = self::b64(json_encode(['typ' => 'JWT', 'alg' => 'ES256'])).'.'.self::b64(json_encode($claims, JSON_UNESCAPED_SLASHES));
        $public = self::unb64((string) config('push.vapid.public_key'));
        $pem = self::privatePem(self::unb64((string) config('push.vapid.private_key')), $public);
        if (!openssl_sign($input, $der, $pem, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('VAPID signing failed; check PUSH_VAPID_PRIVATE_KEY');
        }

        return 'vapid t='.$input.'.'.self::b64(self::derToRaw($der)).', k='.config('push.vapid.public_key');
    }

    public static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function unb64(string $text): string
    {
        return (string) base64_decode(strtr($text, '-_', '+/').str_repeat('=', (4 - strlen($text) % 4) % 4), true);
    }

    private static function newKey(): array
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if (!$key) {
            throw new RuntimeException('OpenSSL could not make a P-256 key');
        }

        return [$key, $key];
    }

    private static function rawPublic($key): string
    {
        $ec = openssl_pkey_get_details($key)['ec'];

        return "\x04".str_pad($ec['x'], 32, "\0", STR_PAD_LEFT).str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);
    }

    private static function rawPrivate($key): string
    {
        return str_pad(openssl_pkey_get_details($key)['ec']['d'], 32, "\0", STR_PAD_LEFT);
    }

    /** The public point for a private scalar, by loading the key without its public part. */
    private static function publicFromPrivate(string $d): string
    {
        // SEC1 ECPrivateKey without the optional public key: OpenSSL computes it.
        $der = hex2bin('30310201010420').$d.hex2bin('a00a06082a8648ce3d030107');
        $key = openssl_pkey_get_private("-----BEGIN EC PRIVATE KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END EC PRIVATE KEY-----\n");
        if (!$key) {
            throw new RuntimeException('Invalid private key');
        }

        return self::rawPublic($key);
    }

    private static function publicPem(string $raw): string
    {
        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode(hex2bin(self::SPKI).$raw), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    private static function privatePem(string $d, string $public): string
    {
        $der = hex2bin('30770201010420').$d.hex2bin('a00a06082a8648ce3d030107a144034200').$public;

        return "-----BEGIN EC PRIVATE KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END EC PRIVATE KEY-----\n";
    }

    /** OpenSSL signs ECDSA as DER (SEQUENCE of two INTEGERs); JWT wants r and s, 32 bytes each. */
    private static function derToRaw(string $der): string
    {
        $offset = 2 + (ord($der[1]) & 0x80 ? ord($der[1]) & 0x7f : 0);
        $out = '';
        for ($i = 0; $i < 2; $i++) {
            $length = ord($der[$offset + 1]);
            $int = ltrim(substr($der, $offset + 2, $length), "\0");
            $out .= str_pad($int, 32, "\0", STR_PAD_LEFT);
            $offset += 2 + $length;
        }

        return $out;
    }
}
