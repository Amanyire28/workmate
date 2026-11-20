<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustHosts as Middleware;

/**
 * Answers only to the site's own host names (config/security.php), so a
 * forged Host header can't make WorkMate build links, such as password
 * reset links, that point somewhere else. Off until APP_PUBLIC_URL is set,
 * and while developing on this computer.
 */
class TrustHosts extends Middleware
{
    /**
     * @return array<int, string>
     */
    public function hosts()
    {
        $hosts = config('security.extra_hosts', []);
        $public = config('security.public_url');
        if ($public && ($host = parse_url($public, PHP_URL_HOST))) {
            array_unshift($hosts, $host);
        }

        return array_values(array_map(function ($host) {
            return '^'.preg_quote(strtolower($host)).'$';
        }, array_unique(array_filter($hosts))));
    }

    protected function shouldSpecifyTrustedHosts()
    {
        return ! $this->app->environment('local') && count($this->hosts()) > 0;
    }
}
