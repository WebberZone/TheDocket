<?php

namespace App\Http\Controllers;

use App\Services\Installer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\MessageBag;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

/**
 * The browser installer (/install). Runs outside the 'web' middleware
 * group — no session, cookies or CSRF exist this early — so validation
 * is done manually and errors are re-rendered directly instead of being
 * flashed to a session that has nowhere to live yet.
 */
class InstallController extends Controller
{
    public function __construct(protected Installer $installer) {}

    /**
     * Step 1: the requirements checklist.
     */
    public function requirements(): View
    {
        return view('install.requirements', [
            'checks' => $this->installer->requirements(),
            'canProceed' => $this->installer->blockingProblems() === [],
        ]);
    }

    /**
     * Step 2: the setup form (site URL + owner account).
     */
    public function form(Request $request): View|RedirectResponse
    {
        if ($this->installer->blockingProblems() !== []) {
            return redirect()->route('install.requirements');
        }

        return view('install.setup', [
            'input' => ['url' => $request->getSchemeAndHttpHost()],
            'errors' => new MessageBag(),
            'installError' => null,
        ]);
    }

    /**
     * Step 3: perform the installation and show the closing checklist.
     */
    public function store(Request $request): View|RedirectResponse|Response
    {
        if ($this->installer->blockingProblems() !== []) {
            return redirect()->route('install.requirements');
        }

        $validator = Validator::make($request->all(), [
            'url' => ['required', 'string', 'url', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $safeInput = $request->except(['password', 'password_confirmation']);

        if ($validator->fails()) {
            return response()->view('install.setup', [
                'input' => $safeInput,
                'errors' => $validator->errors(),
                'installError' => null,
            ], 422);
        }

        $data = $validator->validated();

        try {
            $this->installer->ensureEnvFile();
            $this->installer->ensureAppKey();

            // The password is deliberately NOT written to .env: it is
            // hashed straight into the database below.
            $this->installer->writeEnvValues([
                'APP_URL' => $data['url'],
                'DOCKET_USER_NAME' => $data['name'],
                'DOCKET_USER_EMAIL' => $data['email'],
            ]);

            $this->installer->ensureSqliteDatabase();

            if (! $this->installer->migrate()) {
                throw new RuntimeException('Database migrations failed — check storage/logs/laravel.log.');
            }

            $this->installer->createOwner($data['name'], $data['email'], $data['password']);

            if ($request->boolean('seed_lookups')) {
                $this->installer->seedLookups();
            }
        } catch (Throwable $e) {
            report($e);

            return response()->view('install.setup', [
                'input' => $safeInput,
                'errors' => new MessageBag(),
                'installError' => $e->getMessage(),
            ], 500);
        }

        return view('install.complete', [
            'url' => $data['url'],
            'email' => $data['email'],
            'assetsBuilt' => file_exists(public_path('build/manifest.json')),
        ]);
    }
}
