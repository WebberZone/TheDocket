<?php

namespace App\Http\Middleware;

use App\Exceptions\InstallStateUnknownException;
use App\Services\Installer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the web installer: once Docket has its owner account the /install
 * routes stop existing in any useful sense and just bounce to the login
 * screen, so a live site never exposes a working installer. If the
 * database can't be read, the installer stays closed too (503) rather
 * than mistaking a broken site for a fresh one.
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
        try {
            $installed = $this->installer->isInstalled();
        } catch (InstallStateUnknownException $e) {
            report($e);

            return response()->view('install.unavailable', ['message' => $e->getMessage()], 503);
        }

        if ($installed) {
            return redirect()->route('login');
        }

        return $next($request);
    }
}
