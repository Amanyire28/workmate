<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Blade;
use Minishlink\WebPush\WebPush;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        // Web push client, signed with the server's VAPID keys. On Windows, PHP
        // must start with OPENSSL_CONF set to encrypt messages (see the README).
        $this->app->bind(WebPush::class, function () {
            $client = new WebPush(['VAPID' => [
                'subject' => config('services.webpush.subject'),
                'publicKey' => config('services.webpush.public_key'),
                'privateKey' => config('services.webpush.private_key'),
            ]], [], 20);
            $client->setReuseVAPIDHeaders(true);

            return $client;
        });
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        // Custom @vite Blade directive for Laravel 8 compatibility.
        // The tags are resolved per request (not at compile time) so a new
        // build or starting/stopping `npm run dev` never leaves stale hashes
        // baked into the cached view.
        Blade::directive('vite', function ($expression) {
            return "<?php echo \App\Providers\AppServiceProvider::viteTags({$expression}); ?>";
        });
    }

    /**
     * Build the script/link tags for the given Vite entry points.
     *
     * @param  array  $assets
     * @return string
     */
    public static function viteTags(array $assets)
    {
        $html = '';

        // Dev server mode (HMR): laravel-vite-plugin writes public/hot while `npm run dev` runs
        $hotFile = public_path('hot');
        if (file_exists($hotFile)) {
            $dev = rtrim(trim(file_get_contents($hotFile)), '/');
            $html .= "<script type=\"module\" src=\"{$dev}/@vite/client\"></script>\n    ";
            foreach ($assets as $path) {
                if (str_ends_with($path, '.css')) {
                    $html .= "<link rel=\"stylesheet\" href=\"{$dev}/{$path}\">\n    ";
                } else {
                    $html .= "<script type=\"module\" src=\"{$dev}/{$path}\"></script>\n    ";
                }
            }

            return $html;
        }

        // Production: read from manifest.json
        $manifestPath = public_path('build/manifest.json');
        if (!file_exists($manifestPath)) {
            return '<!-- Vite manifest not found. Run: npm run build -->';
        }
        $manifest = json_decode(file_get_contents($manifestPath), true);
        foreach ($assets as $path) {
            if (!isset($manifest[$path])) continue;
            $entry = $manifest[$path];
            $file  = $entry['file'];
            // Determine by extension whether this is a CSS or JS entry
            if (str_ends_with($file, '.css')) {
                $html .= "<link rel=\"stylesheet\" href=\"/build/{$file}\">\n    ";
            } else {
                // Inject any associated CSS chunks first
                if (!empty($entry['css'])) {
                    foreach ($entry['css'] as $css) {
                        $html .= "<link rel=\"stylesheet\" href=\"/build/{$css}\">\n    ";
                    }
                }
                $html .= "<script type=\"module\" src=\"/build/{$file}\"></script>\n    ";
            }
        }

        return $html;
    }
}
