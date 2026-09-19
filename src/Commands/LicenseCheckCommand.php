<?php

namespace CoreVisys\License\Commands;

use CoreVisys\License\Contracts\LicenseClientInterface;
use Illuminate\Console\Command;

class LicenseCheckCommand extends Command
{
    protected $signature = 'corevisys:license:check {--force : Bypass the check_interval and force an online check}';

    protected $description = 'Check the current license status against the CoreVisys license server.';

    public function handle(LicenseClientInterface $license): int
    {
        $status = $license->check((bool) $this->option('force'));

        $this->table(
            ['Valid', 'Status', 'Type', 'Domain', 'Expires At', 'From Cache', 'Offline'],
            [[
                $status->valid ? 'yes' : 'no',
                $status->status,
                $status->licenseType ?? '—',
                $status->boundDomain ?? '—',
                $status->expiresAt?->toDateTimeString() ?? 'never',
                $status->fromCache ? 'yes' : 'no',
                $status->offline ? 'yes' : 'no',
            ]]
        );

        if (! $status->valid) {
            $this->components->error('License is not currently valid.');

            return self::FAILURE;
        }

        $this->components->info('License is valid.');

        return self::SUCCESS;
    }
}
