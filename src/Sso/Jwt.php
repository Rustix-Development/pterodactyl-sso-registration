<?php

namespace RustixDev\Registration\Sso;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Checks the signature of an OpenID Connect ID token against the provider's published keys
 * (JWKS). RSA (RS256/384/512) and elliptic curve (ES256/384) keys; anything else, "none" and
 * shared-secret algorithms included, is refused.
 */
final class Jwt
{
    public const int CACHE_SECONDS = 3600;

    private const array ALGORITHMS = [
        'RS256' => ['RSA', OPENSSL_ALGO_SHA256, 0],
        'RS384' => ['RSA', OPENSSL_ALGO_SHA384, 0],
        'RS512' => ['RSA', OPENSSL_ALGO_SHA512, 0],
        'ES256' => ['EC', OPENSSL_ALGO_SHA256, 32],
        'ES384' => ['EC', OPENSSL_ALGO_SHA384, 48],
    ];

    /**
     * The token's claims once its signature checks out against a key at $jwksUri.
     *
     * @return array<string, mixed>
     *
     * @throws SsoException
     */
    public static function verify(string $token, string $jwksUri): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new SsoException(SsoException::PROVIDER);
        }

        $header = json_decode(self::decode($parts[0]), true);
        $claims = json_decode(self::decode($parts[1]), true);
        $signature = self::decode($parts[2]);
        $algorithm = is_array($header) ? ($header['alg'] ?? null) : null;
        if (! is_array($claims) || ! is_string($algorithm) || ! isset(self::ALGORITHMS[$algorithm]) || $signature === '') {
            throw new SsoException(SsoException::PROVIDER);
        }

        [$type, $hash, $size] = self::ALGORITHMS[$algorithm];
        $kid = is_string($header['kid'] ?? null) ? $header['kid'] : null;

        // A key the cached set does not have may be new: fetch the set again once.
        $key = self::key(self::keys($jwksUri, false), $kid, $type) ?? self::key(self::keys($jwksUri, true), $kid, $type);
        if ($key === null) {
            throw new SsoException(SsoException::PROVIDER);
        }

        if ($type === 'EC') {
            $signature = self::ecSignatureToDer($signature, $size);
        }

        if (openssl_verify($parts[0].'.'.$parts[1], $signature, $key, $hash) !== 1) {
            throw new SsoException(SsoException::PROVIDER);
        }

        return $claims;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function keys(string $uri, bool $fresh): array
    {
        $cacheKey = 'registration:sso:jwks:'.sha1($uri);
        if ($fresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, self::CACHE_SECONDS, function () use ($uri): array {
            $keys = Http::acceptJson()->timeout(10)->get($uri)->throw()->json('keys');

            return is_array($keys) ? array_values(array_filter($keys, 'is_array')) : [];
        });
    }

    /**
     * @param  list<array<string, mixed>>  $keys
     */
    private static function key(array $keys, ?string $kid, string $type): ?string
    {
        $matching = array_values(array_filter($keys, fn (array $key): bool => ($key['kty'] ?? null) === $type
            && ($kid === null || ($key['kid'] ?? null) === $kid)
            && (! isset($key['use']) || $key['use'] === 'sig')));

        // Without a kid, only a set with a single key of the type is unambiguous.
        if ($matching === [] || ($kid === null && count($matching) > 1)) {
            return null;
        }

        return self::pem($matching[0]);
    }

    /**
     * @param  array<string, mixed>  $jwk
     */
    public static function pem(array $jwk): ?string
    {
        if (($jwk['kty'] ?? null) === 'RSA' && is_string($jwk['n'] ?? null) && is_string($jwk['e'] ?? null)) {
            $rsa = self::sequence(self::integer(self::decode($jwk['n'])).self::integer(self::decode($jwk['e'])));
            $info = self::sequence(self::sequence("\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00").self::bitString($rsa));
        } elseif (($jwk['kty'] ?? null) === 'EC' && is_string($jwk['x'] ?? null) && is_string($jwk['y'] ?? null)) {
            $curve = match ($jwk['crv'] ?? null) {
                'P-256' => ["\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07", 32],
                'P-384' => ["\x06\x05\x2b\x81\x04\x00\x22", 48],
                default => null,
            };
            $x = self::decode($jwk['x']);
            $y = self::decode($jwk['y']);
            if ($curve === null || strlen($x) !== $curve[1] || strlen($y) !== $curve[1]) {
                return null;
            }
            $info = self::sequence(self::sequence("\x06\x07\x2a\x86\x48\xce\x3d\x02\x01".$curve[0]).self::bitString("\x04".$x.$y));
        } else {
            return null;
        }

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($info), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    public static function decode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4), true);

        return $decoded === false ? '' : $decoded;
    }

    private static function ecSignatureToDer(string $signature, int $size): string
    {
        if (strlen($signature) !== 2 * $size) {
            return '';
        }

        return self::sequence(self::integer(substr($signature, 0, $size)).self::integer(substr($signature, $size)));
    }

    private static function length(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        $bytes = ltrim(pack('N', $length), "\x00");

        return chr(0x80 | strlen($bytes)).$bytes;
    }

    private static function sequence(string $content): string
    {
        return "\x30".self::length(strlen($content)).$content;
    }

    private static function integer(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '' || ord($bytes[0]) > 0x7F) {
            $bytes = "\x00".$bytes;
        }

        return "\x02".self::length(strlen($bytes)).$bytes;
    }

    private static function bitString(string $content): string
    {
        return "\x03".self::length(strlen($content) + 1)."\x00".$content;
    }
}
