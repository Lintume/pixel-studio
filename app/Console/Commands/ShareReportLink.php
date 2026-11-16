<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/** Prints a read-only link to the operator report. See OperatorReportAccess. */
class ShareReportLink extends Command
{
    protected $signature = 'report:share-link
        {--until= : Last day the link works (UTC), e.g. 2026-12-16; default 14 days}
        {--base= : Origin to put in front of the path; default APP_URL}';

    protected $description = 'Print a signed, expiring, read-only link to the operator report';

    public function handle(): int
    {
        $until = $this->option('until')
            ? Carbon::parse($this->option('until'), 'UTC')->endOfDay()
            : Carbon::now('UTC')->addDays(14)->endOfDay();

        $path = URL::temporarySignedRoute('admin.report', $until, absolute: false);
        $base = rtrim((string) ($this->option('base') ?: config('app.url')), '/');

        $this->line($base.$path);
        $this->comment("Works until {$until->toDateTimeString()} UTC.");

        return self::SUCCESS;
    }
}
