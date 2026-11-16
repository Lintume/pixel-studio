<?php

namespace App\Console\Commands;

use App\Actions\ApplyProviderState;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use Illuminate\Console\Command;

/**
 * Webhook delivery isn't guaranteed (six failed attempts and Pixelforge stops),
 * so anything still in flight a few minutes after its last change is asked about
 * directly. Runs every minute from the scheduler.
 */
class SyncGenerations extends Command
{
    protected $signature = 'generations:sync {--older-than=2 : Minutes since the last update}';

    protected $description = 'Poll Pixelforge for generations whose webhooks are overdue';

    public function handle(PixelforgeClient $pixelforge, ApplyProviderState $apply): int
    {
        $stale = Generation::query()
            ->whereIn('status', [GenerationStatus::Queued, GenerationStatus::Processing])
            ->whereNotNull('provider_id')
            ->where('updated_at', '<', now()->subMinutes((int) $this->option('older-than')))
            ->oldest('updated_at')
            ->limit(20) // stay well inside 60 requests a minute
            ->get();

        foreach ($stale as $generation) {
            try {
                $apply($generation, $pixelforge->getGeneration($generation->provider_id));
            } catch (PixelforgeException $e) {
                $this->warn("{$generation->id}: {$e->errorCode}");

                if ($e->errorCode === 'rate_limit_exceeded') {
                    break; // next minute
                }

                continue;
            }

            // Bump updated_at so a generation that is genuinely still running
            // isn't polled again on the very next run.
            $generation->touch();
        }

        $this->info("Checked {$stale->count()} generation(s).");

        return self::SUCCESS;
    }
}
