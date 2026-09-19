<?php

namespace CoreVisys\License\Tests\Unit;

use CoreVisys\License\Services\LicenseStorage;
use CoreVisys\License\Tests\TestCase;
use Illuminate\Support\Facades\DB;

class LicenseStorageTest extends TestCase
{
    public function test_license_key_is_encrypted_at_rest(): void
    {
        $storage = new LicenseStorage(config('corevisys-license'));

        $storage->put('test-product', [
            'license_key' => 'PLAINTEXT-KEY-9999',
            'status' => 'active',
        ]);

        $row = DB::table('corevisys_license_cache')->where('product_code', 'test-product')->first();

        $this->assertNotNull($row);
        $this->assertStringNotContainsString('PLAINTEXT-KEY-9999', $row->encrypted_license_key);

        $decrypted = $storage->decryptLicenseKey((array) $row);
        $this->assertSame('PLAINTEXT-KEY-9999', $decrypted);
    }

    public function test_forget_clears_cached_record(): void
    {
        $storage = new LicenseStorage(config('corevisys-license'));
        $storage->put('test-product', ['status' => 'active']);

        $this->assertNotNull($storage->get('test-product'));

        $storage->forget('test-product');

        $this->assertNull($storage->get('test-product'));
    }
}
