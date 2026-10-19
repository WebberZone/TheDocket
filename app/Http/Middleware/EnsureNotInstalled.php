<?php

namespace App\Http\Middleware;

use App\Services\Installer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the web installer: once Docket has its owner account the /install
 * routes stop existing in any useful sense and just bounce to the login
 * screen, so a live site never exposes a working installer.
 */
class EnsureNotInstalled
{
    public function __construct(protected Installer $installer) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->installer->isInstalled()) {
            return redirect()->route('login');
        }

        return $next($request);
    }
}
