<?php

namespace CoreVisys\License\Commands;

use Carbon\Carbon;
use CoreVisys\License\Contracts\LicenseStorageInterface;
use Illuminate\Console\Command;

class LicenseStatusCommand extends Command
{
    protected $signature = 'corevisys:license:status';

    protected $description = 'Show the cached license status without contacting the server.';

    public function handle(LicenseStorageInterface $storage): int
    {
        $productCode = config('corevisys-license.product_code');
        $record = $storage->get($productCode);

        if (! $record) {
            $this->components->warn('No cached license data found. Run corevisys:license:activate first.');

            return self::FAILURE;
        }

        $gracePeriodHours = (int) config('corevisys-license.grace_period', 72);
        $lastCheck = $record['last_successful_check_at'] ?? null;
        $graceExpiresAt = $lastCheck ? Carbon::parse($lastCheck)->addHours($gracePeriodHours) : null;

        $this->table(
            ['Field', 'Value'],
            [
                ['Status', $record['status'] ?? 'unknown'],
                ['Type', $record['license_type'] ?? '—'],
                ['Domain', $record['bound_domain'] ?? '—'],
                ['Expires At', $record['expires_at'] ?? 'never'],
                ['Last Checked', $record['last_checked_at'] ?? '—'],
                ['Next Check', $record['next_check_at'] ?? '—'],
                ['Offline Grace Until', $graceExpiresAt?->toDateTimeString() ?? '—'],
                ['In Grace Window', $graceExpiresAt?->isFuture() ? 'yes' : 'no'],
                ['Last Error', $record['last_error_message'] ?? '—'],
            ]
        );

        return self::SUCCESS;
    }
}
