<?php

namespace App\Services\Payments;

/**
 * What WorkMate needs from a mobile money gateway, whichever one it is. A real gateway implements this and is registered in
 * GatewayRegistry; nothing else in the app knows a gateway's name, endpoint or secret. No gateway ever sees a PIN: the provider approves
 * the request on their own phone.
 */
interface PaymentGateway
{
    /** Short stable name, used in the callback address (/payments/callbacks/{key}) */
    public function key(): string;

    /** Whether this gateway can collect through the method (mtn_momo, airtel_money) */
    public function supports(string $method): bool;

    /**
     * Asks the gateway to send an authorisation request to the payer's phone.
     *
     * @param  array{reference: string, amount_ugx: int, method: string, msisdn: string}  $request
     * @return array{gateway_reference: string}
     */
    public function initiate(array $request): array;

    /**
     * Checks that a callback really came from the gateway (its signature) and returns what it says.
     * Throws InvalidArgumentException when it cannot be trusted.
     *
     * @return array{reference: string, gateway_reference: string, status: string, amount_ugx: int}  status: successful | failed | cancelled
     */
    public function verifyCallback(string $body, ?string $signature): array;
}
