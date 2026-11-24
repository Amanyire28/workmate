<?php

namespace App\Services\Payments;

use InvalidArgumentException;

/**
 * A stand-in gateway for local development and automated tests ONLY. It is registered by GatewayRegistry exclusively when the app runs
 * in the "local" or "testing" environment, so it can never exist in production and can never settle a real obligation there.
 * Its callbacks are signed like a real gateway's, so the same verification path is exercised.
 */
class SimulatedGateway implements PaymentGateway
{
    public function key(): string
    {
        return 'simulator';
    }

    public function supports(string $method): bool
    {
        return in_array($method, ['mtn_momo', 'airtel_money'], true);
    }

    public function initiate(array $request): array
    {
        return ['gateway_reference' => 'SIM-'.strtoupper(bin2hex(random_bytes(6)))];
    }

    public function verifyCallback(string $body, ?string $signature): array
    {
        if (! $signature || ! hash_equals($this->sign($body), $signature)) {
            throw new InvalidArgumentException('Bad signature');
        }
        $d = json_decode($body, true);
        if (! is_array($d) || ! isset($d['reference'], $d['gateway_reference'], $d['status'], $d['amount_ugx'])
            || ! in_array($d['status'], ['successful', 'failed', 'cancelled'], true)) {
            throw new InvalidArgumentException('Bad payload');
        }

        return ['reference' => (string) $d['reference'], 'gateway_reference' => (string) $d['gateway_reference'], 'status' => $d['status'], 'amount_ugx' => (int) $d['amount_ugx']];
    }

    public function sign(string $body): string
    {
        return hash_hmac('sha256', $body, (string) config('app.key').'|simulator');
    }

    /** The signed callback a phone approval (or refusal) would cause: [body, signature] */
    public function callbackFor(string $reference, string $gatewayReference, int $amount, string $status): array
    {
        $body = json_encode(['reference' => $reference, 'gateway_reference' => $gatewayReference, 'status' => $status, 'amount_ugx' => $amount]);

        return [$body, $this->sign($body)];
    }
}
