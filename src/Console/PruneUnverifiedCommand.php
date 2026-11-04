<?php

namespace RustixDev\Registration\Console;

use Illuminate\Console\Command;
use RustixDev\Registration\Services\UnverifiedCleanup;

class PruneUnverifiedCommand extends Command
{
    protected $signature = 'registration:prune-unverified {--dry-run : List the accounts without deleting them}';

    protected $description = 'Delete accounts created on the sign-up page that never verified their email.';

    public function handle(UnverifiedCleanup $cleanup): int
    {
        if ($this->option('dry-run')) {
            $signups = $cleanup->candidates()->with('user')->get();
            foreach ($signups as $signup) {
                $this->line(sprintf('%d %s <%s>', $signup->user_id, $signup->user?->username, $signup->user?->email));
            }
            $this->info($signups->count().' account(s) would be deleted.');

            return self::SUCCESS;
        }

        $this->info($cleanup->run().' account(s) deleted.');

        return self::SUCCESS;
    }
}
