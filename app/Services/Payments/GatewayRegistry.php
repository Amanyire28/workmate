<?php

namespace App\Services\Payments;

/**
 * The gateways WorkMate can collect through. Real gateways are added here when they are approved; there are none yet. The simulator exists
 * only in the "local" and "testing" environments (never in production), and only when config('workmate.payments.simulator') allows it.
 */
class GatewayRegistry
{
    /** @return PaymentGateway[] */
    public function all(): array
    {
        $gateways = [];
        if ($this->simulatorAllowed()) {
            $gateways[] = new SimulatedGateway();
        }

        return $gateways;
    }

    public function simulatorAllowed(): bool
    {
        return app()->environment(['local', 'testing']) && (bool) config('workmate.payments.simulator', true);
    }

    public function forMethod(string $method): ?PaymentGateway
    {
        foreach ($this->all() as $gateway) {
            if ($gateway->supports($method)) {
                return $gateway;
            }
        }

        return null;
    }

    public function byKey(string $key): ?PaymentGateway
    {
        foreach ($this->all() as $gateway) {
            if ($gateway->key() === $key) {
                return $gateway;
            }
        }

        return null;
    }
}
