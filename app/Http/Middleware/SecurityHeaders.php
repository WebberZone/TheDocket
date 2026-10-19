<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline browser-hardening headers on every web response. Laravel does
 * not set these itself and the app is internet-facing:
 *
 *  - nosniff stops a browser second-guessing the Content-Type on stored
 *    article images and rendering one as something more dangerous.
 *  - SAMEORIGIN blocks another site framing the app for clickjacking
 *    (nothing legitimate frames Docket; the bookmarklet opens a window).
 *  - Referrer-Policy keeps app URLs out of outbound request headers.
 */
class SecurityHeaders
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'same-origin');

        return $response;
    }
}
