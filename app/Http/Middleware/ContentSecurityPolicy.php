<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * A strict Content-Security-Policy on every web page.
 *
 * The reader prints third-party article HTML unescaped. The sanitiser is
 * what keeps scripts out of it; this is the second lock: even if markup
 * slipped past the sanitiser, the browser would refuse to run inline
 * scripts, inline event handlers, javascript: URLs or code from any other
 * origin.
 *
 *  - Scripts: this origin, plus inline <script> tags carrying the
 *    per-request nonce (Vite::useCspNonce() stamps it on the built assets
 *    too). No 'unsafe-inline', no 'unsafe-eval': Alpine is the CSP build.
 *  - Styles and fonts: this origin and the Bunny Fonts stylesheet. No
 *    inline style attributes.
 *  - Images: this origin, plus any https host, because article images that
 *    weren't downloaded keep their remote src.
 *  - Nothing may frame the app from elsewhere, and forms only post here.
 *
 * Responses that already carry a policy (stored article images send
 * "default-src 'none'") keep their own.
 */
class ContentSecurityPolicy
{
    public const FONT_HOST = 'https://fonts.bunny.net';

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();

        $response = $next($request);

        if (! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', $this->policy($nonce));
        }

        return $response;
    }

    protected function policy(string $nonce): string
    {
        $script = ["'self'", "'nonce-{$nonce}'"];
        $style = ["'self'", self::FONT_HOST];
        $connect = ["'self'"];

        // `npm run dev`: assets come from the Vite dev server, which also
        // injects CSS through <style> tags and talks over a websocket.
        if (Vite::isRunningHot()) {
            $hot = rtrim(trim((string) file_get_contents(Vite::hotFile())), '/');
            $socket = (string) preg_replace('#^http#', 'ws', $hot);

            $script[] = $hot;
            $style[] = $hot;
            $style[] = "'unsafe-inline'";
            $connect[] = $hot;
            $connect[] = $socket;
        }

        $directives = [
            'default-src' => ["'none'"],
            'script-src' => $script,
            'style-src' => $style,
            'font-src' => ["'self'", self::FONT_HOST],
            'img-src' => ["'self'", 'https:', 'data:'],
            'connect-src' => $connect,
            'manifest-src' => ["'self'"],
            'worker-src' => ["'self'"],
            'form-action' => ["'self'"],
            'frame-ancestors' => ["'self'"],
            'base-uri' => ["'none'"],
        ];

        return collect($directives)
            ->map(fn (array $sources, string $directive): string => $directive.' '.implode(' ', $sources))
            ->implode('; ');
    }
}
