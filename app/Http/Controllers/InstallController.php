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
    public function form(Request $request): Response|RedirectResponse
    {
        if ($this->installer->blockingProblems() !== []) {
            return redirect()->route('install.requirements');
        }

        // Creates the code on first visit, so it is there to read.
        $this->installer->setupCode();

        return $this->setupView(['url' => $request->getSchemeAndHttpHost()], new MessageBag(), null);
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
            'setup_code' => ['required', 'string', 'max:64'],
            'url' => ['required', 'string', 'url', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $validator->after(function ($validator) use ($request): void {
            if (is_string($request->input('setup_code')) && ! $this->installer->checkSetupCode($request->input('setup_code'))) {
                $validator->errors()->add('setup_code', 'That setup code is not right. Copy it from the file named below.');
            }
        });

        $safeInput = $request->except(['password', 'password_confirmation']);

        if ($validator->fails()) {
            return $this->setupView($safeInput, $validator->errors(), null, 422);
        }

        $data = $validator->validated();

        // One installation at a time, and the "already installed?" question
        // asked again inside the lock: two requests that both got past the
        // middleware can't both create an owner.
        $lock = $this->installer->acquireLock();

        if ($lock === null) {
            return $this->setupView($safeInput, new MessageBag(), 'Another installation is running right now. Wait a moment, then reload.', 409);
        }

        try {
            if ($this->installer->isInstalled()) {
                return redirect()->route('login');
            }

            $this->installer->ensureEnvFile();
            $this->installer->ensureAppKey();
            $this->installer->ensureSqliteDatabase();

            if (! $this->installer->migrate()) {
                throw new RuntimeException('Database migrations failed — check storage/logs/laravel.log.');
            }

            $this->installer->createOwner($data['name'], $data['email'], $data['password']);

            // Configuration changes only once the account exists, so a
            // failed or refused install leaves .env as it was. The password
            // is deliberately NOT written to .env: it is hashed into the
            // database above.
            $this->installer->writeEnvValues([
                'APP_URL' => $data['url'],
                'DOCKET_USER_NAME' => $data['name'],
                'DOCKET_USER_EMAIL' => $data['email'],
            ]);

            $this->installer->markInstalled();
        } catch (Throwable $e) {
            report($e);

            return $this->setupView($safeInput, new MessageBag(), $e->getMessage(), 500);
        } finally {
            $this->installer->releaseLock($lock);
        }

        return view('install.complete', [
            'url' => $data['url'],
            'email' => $data['email'],
            'assetsBuilt' => file_exists(public_path('build/manifest.json')),
        ]);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    protected function setupView(array $input, MessageBag $errors, ?string $installError, int $status = 200): Response
    {
        return response()->view('install.setup', [
            'input' => $input,
            'errors' => $errors,
            'installError' => $installError,
            'codeLocation' => $this->installer->setupCodeLocation(),
        ], $status);
    }
}
