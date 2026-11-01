<?php

namespace App\Console\Commands;

use App\Services\PushNotifications;
use Illuminate\Console\Command;

/**
 * Creates the VAPID key pair that push notifications are signed with. It only
 * prints the keys: paste them into .env yourself. Changing the keys later
 * turns notifications off on every device until people turn them on again.
 */
class CreatePushKeys extends Command
{
    protected $signature = 'workmate:push-keys';

    protected $description = 'Create the VAPID keys for push notifications (printed, not saved)';

    public function handle(): int
    {
        if (PushNotifications::configured()) {
            $this->warn('This server already has push keys. Replacing them turns notifications off on every device');
            $this->warn('until people turn them on again. Only replace them if the private key was exposed.');
            if (! $this->confirm('Create new keys anyway?')) {
                return 0;
            }
        }

        $keys = $this->createKeys();
        if (! $keys) {
            $this->error('OpenSSL could not create a key. Check that the openssl PHP extension is enabled.');

            return 1;
        }

        $this->line('Add these lines to .env, then run: php artisan config:clear');
        $this->newLine();
        $this->line('VAPID_PUBLIC_KEY='.$keys['public']);
        $this->line('VAPID_PRIVATE_KEY='.$keys['private']);
        $this->newLine();
        $this->line('Keep the private key secret: never commit it or share it.');

        return 0;
    }

    /** A P-256 key pair in the URL-safe base64 form browsers and push services use */
    private function createKeys(): ?array
    {
        $options = ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'];
        // PHP on Windows ships its own OpenSSL config, which isn't found by default
        $bundled = dirname(PHP_BINARY).'/extras/ssl/openssl.cnf';
        if (PHP_OS_FAMILY === 'Windows' && ! getenv('OPENSSL_CONF') && is_file($bundled)) {
            $options['config'] = $bundled;
        }

        $key = openssl_pkey_new($options);
        if (! $key) {
            return null;
        }
        $ec = openssl_pkey_get_details($key)['ec'];
        $pad = function (string $bytes) {
            return str_pad($bytes, 32, "\0", STR_PAD_LEFT);
        };
        $encode = function (string $bytes) {
            return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
        };

        return [
            'public' => $encode("\x04".$pad($ec['x']).$pad($ec['y'])),
            'private' => $encode($pad($ec['d'])),
        ];
    }
}
