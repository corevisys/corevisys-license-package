<?php

namespace CoreVisys\License\Commands;

use CoreVisys\License\Contracts\LicenseClientInterface;
use Illuminate\Console\Command;

class LicenseActivateCommand extends Command
{
    protected $signature = 'corevisys:license:activate {key? : License key to activate (defaults to COREVISYS_LICENSE_KEY)}';

    protected $description = 'Activate this installation against the CoreVisys license server.';

    public function handle(LicenseClientInterface $license): int
    {
        $key = $this->argument('key');

        $this->components->task('Collecting fingerprint and domain', fn () => true);

        $result = $license->activate($key);

        if (! $result->success) {
            $this->components->error($result->message ?? 'Activation failed.');

            return self::FAILURE;
        }

        $this->components->info($result->message ?? 'License activated successfully.');

        if ($result->status) {
            $this->table(
                ['Status', 'Type', 'Domain', 'Expires At'],
                [[
                    $result->status->status,
                    $result->status->licenseType ?? '—',
                    $result->status->boundDomain ?? '—',
                    $result->status->expiresAt?->toDateTimeString() ?? 'never',
                ]]
            );
        }

        return self::SUCCESS;
    }
}
