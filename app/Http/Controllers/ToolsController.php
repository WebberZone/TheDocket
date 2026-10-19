<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\View\View;
use Symfony\Component\Process\Process;

/**
 * FreeScout-style maintenance tools: each button on /tools runs one
 * pre-defined artisan command and shows its console output. The rebuild tool
 * is isolated in a sub-process so it can cache config/routes/views/events
 * without mutating the current request's session. The registry below is a hard
 * whitelist — the route only ever accepts a registry key, never a command string,
 * so nothing user-supplied reaches the console.
 */
class ToolsController extends Controller
{
    /**
     * The available tools, keyed by the slug used in the /tools/{tool} URL.
     *
     * @return array<string, array{label: string, description: string, command: string, params: array<string, mixed>}>
     */
    public static function tools(): array
    {
        return [
            'clear-cache' => [
                'label' => 'Clear cache',
                'description' => 'Flush every cache — config, routes, views, events, and the application cache (optimize:clear). The safe first step when the app behaves oddly after a change.',
                'command' => 'optimize:clear',
                'params' => [],
            ],
            'optimize' => [
                'label' => 'Rebuild caches',
                'description' => 'Re-cache events, routes and views for faster requests (docket:optimize). Run after deploying; config cache is skipped because it must be rebuilt from the command line in the correct environment.',
                'command' => 'docket:optimize',
                'params' => [],
            ],
            'migrate' => [
                'label' => 'Run migrations',
                'description' => 'Apply any pending database migrations (migrate --force). Safe to run repeatedly — it does nothing when the schema is already up to date.',
                'command' => 'migrate',
                'params' => ['--force' => true],
            ],
            'queue-restart' => [
                'label' => 'Restart queue worker',
                'description' => 'Signal the queue worker to restart after it finishes its current job (queue:restart). Needed after deploying code changes — workers keep the old code in memory until restarted.',
                'command' => 'queue:restart',
                'params' => [],
            ],
            'failed-jobs' => [
                'label' => 'List failed jobs',
                'description' => 'Show jobs that exhausted their retries (queue:failed) — usually OCR jobs that hit an API error.',
                'command' => 'queue:failed',
                'params' => [],
            ],
            'retry-failed' => [
                'label' => 'Retry failed jobs',
                'description' => 'Push every failed job back onto the queue (queue:retry all).',
                'command' => 'queue:retry',
                'params' => ['id' => ['all']],
            ],
            'ocr-requeue' => [
                'label' => 'Re-queue missing OCR',
                'description' => 'Queue OCR extraction for receipts that never got a result (docket:ocr) — e.g. uploads made while the queue worker was down.',
                'command' => 'docket:ocr',
                'params' => [],
            ],
            'prune-captures' => [
                'label' => 'Prune abandoned captures',
                'description' => 'Delete pending capture photos older than 24 hours that never became an expense (docket:prune-captures). The scheduler does this nightly; this runs it now.',
                'command' => 'docket:prune-captures',
                'params' => [],
            ],
            'backup-run' => [
                'label' => 'Run backup now',
                'description' => 'Take an off-site backup immediately (backup:run) instead of waiting for the nightly scheduled one. Can take a minute — the receipts directory is zipped and uploaded.',
                'command' => 'backup:run',
                'params' => [],
            ],
            'backup-list' => [
                'label' => 'List backups',
                'description' => 'Show existing backups per destination with sizes and dates (backup:list).',
                'command' => 'backup:list',
                'params' => [],
            ],
            'schedule-list' => [
                'label' => 'List scheduled tasks',
                'description' => 'Show everything on the scheduler and when it next runs (schedule:list) — nightly backup, capture pruning, etc.',
                'command' => 'schedule:list',
                'params' => [],
            ],
        ];
    }

    public function index(): View
    {
        return view('tools.index', ['tools' => self::tools()]);
    }

    /**
     * Run one whitelisted tool and flash its console output back to the page.
     */
    public function run(string $tool): RedirectResponse
    {
        $definition = self::tools()[$tool] ?? abort(404);

        if ($tool === 'optimize') {
            $binary = dirname(PHP_BINARY).'/'.preg_replace('/-fpm$/', '', basename(PHP_BINARY));
            $process = new Process([$binary, 'artisan', $definition['command']], base_path());
            $process->run();
            $exitCode = $process->getExitCode() ?? 1;
            $output = trim($process->getOutput());
            if ($output === '') {
                $output = trim($process->getErrorOutput()) ?: '(no output)';
            }
        } else {
            $exitCode = Artisan::call($definition['command'], $definition['params']);
            $output = trim(Artisan::output());
        }

        return redirect('/tools')->with([
            'tool_ran' => $definition['label'],
            'tool_exit_code' => $exitCode,
            'tool_output' => $output !== '' ? $output : '(no output)',
        ]);
    }
}
