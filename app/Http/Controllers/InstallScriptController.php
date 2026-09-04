<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/**
 * Serves the device setup script.
 *
 * Instance-served rather than copied from documentation, because the two things
 * a device cannot work out for itself are exactly the two things this fills in:
 * the instance address, and which marketplace the plugin comes from. A fork
 * points its own installs at its own marketplace without editing anything.
 *
 * Unauthenticated by necessity — a device that has never connected has no
 * credentials — so the response carries nothing that is not already public.
 */
class InstallScriptController extends Controller
{
    public function __invoke(): Response
    {
        $script = strtr(file_get_contents(resource_path('install/install.sh.stub')), [
            '{{INSTANCE_URL}}' => $this->shellSafe(rtrim((string) config('app.url'), '/'), 'https://<your-instance>'),
            '{{MARKETPLACE}}' => $this->shellSafe((string) config('mnemon.plugin.marketplace'), 'coopers98/mnemon'),
        ]);

        return response($script, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    /**
     * Both values land inside single-quoted shell strings, so a quote in either
     * would end the string and run whatever followed. These come from config
     * rather than from a request, but a script served to every device is the
     * wrong place to rely on that.
     */
    private function shellSafe(string $value, string $fallback): string
    {
        return preg_match('#^[A-Za-z0-9:/._@-]+$#', $value) === 1 ? $value : $fallback;
    }
}
