<?php

namespace App\Services;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The private Cloudflare R2 bucket for community videos (config/videos.php).
 *
 * Everything is done with short-lived signed addresses (AWS Signature
 * Version 4, which R2 speaks), so no storage SDK is needed: phones upload
 * and play with them directly, and the server only asks for an object's size,
 * reads small byte ranges of its header, or deletes it. Video files are never
 * downloaded to, or passed through, the WorkMate server.
 */
class R2Storage
{
    /** All the keys are in the server's .env */
    public function configured(): bool
    {
        $c = config('videos.r2');

        return ! empty($c['access_key_id']) && ! empty($c['secret_access_key']) && ! empty($c['bucket'])
            && (! empty($c['endpoint']) || ! empty($c['account_id']));
    }

    /**
     * A signed address for one request on one object.
     *
     * @param  array<string, string>  $headers  headers the request must carry exactly (e.g. Content-Type, Content-Length)
     */
    public function presign(string $method, string $key, int $seconds, array $headers = []): string
    {
        $this->ensureConfigured();
        $endpoint = parse_url($this->endpoint());
        $host = $endpoint['host'].(isset($endpoint['port']) ? ':'.$endpoint['port'] : '');
        $path = rtrim($endpoint['path'] ?? '', '/').'/'.rawurlencode((string) config('videos.r2.bucket')).'/'.self::encodeKey($key);

        return self::presignUrl(
            $method, ($endpoint['scheme'] ?? 'https'), $host, $path, 'auto', 's3',
            (string) config('videos.r2.access_key_id'), (string) config('videos.r2.secret_access_key'),
            $seconds, $headers, new DateTimeImmutable('now', new DateTimeZone('UTC'))
        );
    }

    /**
     * The object's size and type, or null when it isn't there.
     *
     * @return array{size: int, type: string}|null
     * @throws RuntimeException when storage can't be reached
     */
    public function head(string $key): ?array
    {
        $response = Http::timeout(15)->head($this->presign('HEAD', $key, 300));
        if ($response->status() === 404) {
            return null;
        }
        if (! $response->successful()) {
            throw new RuntimeException('R2 HEAD failed with HTTP '.$response->status());
        }

        return ['size' => (int) $response->header('Content-Length'), 'type' => (string) $response->header('Content-Type')];
    }

    /**
     * Bytes $offset to $offset + $length - 1 of the object, held in memory only.
     *
     * @throws RuntimeException
     */
    public function read(string $key, int $offset, int $length): string
    {
        if ($length <= 0) {
            return '';
        }
        $response = Http::timeout(20)
            ->withHeaders(['Range' => 'bytes='.$offset.'-'.($offset + $length - 1)])
            ->get($this->presign('GET', $key, 300));
        if ($response->status() !== 206 && $response->status() !== 200) {
            throw new RuntimeException('R2 ranged GET failed with HTTP '.$response->status());
        }
        $body = $response->body();
        if ($response->status() === 200) {
            // Storage ignored the range (it only does that for whole small objects)
            $body = substr($body, $offset, $length);
        }

        return (string) substr($body, 0, $length);
    }

    /**
     * Deletes the object; true when it's gone (including when it was never there).
     *
     * @throws RuntimeException when storage can't be reached
     */
    public function delete(string $key): bool
    {
        $response = Http::timeout(15)->delete($this->presign('DELETE', $key, 300));
        if ($response->successful() || $response->status() === 404) {
            return true;
        }

        throw new RuntimeException('R2 DELETE failed with HTTP '.$response->status());
    }

    private function endpoint(): string
    {
        return (string) (config('videos.r2.endpoint') ?: 'https://'.config('videos.r2.account_id').'.r2.cloudflarestorage.com');
    }

    private function ensureConfigured(): void
    {
        if (! $this->configured()) {
            throw new RuntimeException('Video storage (R2) is not configured.');
        }
    }

    /** Object keys are made by the server ([a-z0-9/._-]); each path segment encoded */
    private static function encodeKey(string $key): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $key)));
    }

    /**
     * A presigned URL (AWS Signature Version 4, query-string form).
     * The body isn't signed (UNSIGNED-PAYLOAD); listed headers are.
     *
     * @param  array<string, string>  $headers
     */
    public static function presignUrl(
        string $method, string $scheme, string $host, string $path, string $region, string $service,
        string $accessKey, string $secret, int $seconds, array $headers, DateTimeInterface $now
    ): string {
        $amzDate = $now->format('Ymd\THis\Z');
        $day = $now->format('Ymd');
        $scope = "$day/$region/$service/aws4_request";

        $signed = ['host' => $host];
        foreach ($headers as $name => $value) {
            $signed[strtolower($name)] = trim((string) $value);
        }
        ksort($signed);
        $signedNames = implode(';', array_keys($signed));

        $query = [
            'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential' => "$accessKey/$scope",
            'X-Amz-Date' => $amzDate,
            'X-Amz-Expires' => (string) $seconds,
            'X-Amz-SignedHeaders' => $signedNames,
        ];
        ksort($query);
        $canonicalQuery = implode('&', array_map(function ($k, $v) {
            return rawurlencode($k).'='.rawurlencode($v);
        }, array_keys($query), $query));

        $canonicalHeaders = '';
        foreach ($signed as $name => $value) {
            $canonicalHeaders .= "$name:$value\n";
        }

        $canonicalRequest = implode("\n", [strtoupper($method), $path, $canonicalQuery, $canonicalHeaders, $signedNames, 'UNSIGNED-PAYLOAD']);
        $stringToSign = implode("\n", ['AWS4-HMAC-SHA256', $amzDate, $scope, hash('sha256', $canonicalRequest)]);

        $key = hash_hmac('sha256', $day, 'AWS4'.$secret, true);
        $key = hash_hmac('sha256', $region, $key, true);
        $key = hash_hmac('sha256', $service, $key, true);
        $key = hash_hmac('sha256', 'aws4_request', $key, true);
        $signature = hash_hmac('sha256', $stringToSign, $key);

        return "$scheme://$host$path?$canonicalQuery&X-Amz-Signature=$signature";
    }
}
