<?php

namespace Tests\Feature\Settings;

use App\Models\AppSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\CreatesDomainData;
use Tests\TestCase;

/**
 * @group settings
 */
class CurrencySettingsPersistenceTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDomainData;

    public function test_toman_per_1000_dinar_survives_cache_clear(): void
    {
        $this->actingAsRole('admin');

        $this->putJson('/api/settings', [
            'toman_per_1000_dinar' => 120000,
            'rate_notes' => 'test rate',
        ])->assertOk()
            ->assertJsonPath('toman_per_1000_dinar', 120000)
            ->assertJsonPath('toman_to_dinar_rate', 120000)
            ->assertJsonPath('rate_notes', 'test rate');

        $this->assertSame(120000.0, (float) AppSetting::get('almanahel.toman_per_1000_dinar'));
        $this->assertNotNull(AppSetting::get('almanahel.rate_updated_at'));

        Cache::flush();

        $this->getJson('/api/settings')
            ->assertOk()
            ->assertJsonPath('toman_per_1000_dinar', 120000)
            ->assertJsonPath('rate_notes', 'test rate');
    }

    public function test_legacy_small_multiplier_is_ignored_for_default(): void
    {
        // Old wrong semantics stored tiny "dinar per toman" values like 50.
        AppSetting::put('almanahel.toman_to_dinar_rate', 50);

        $this->actingAsRole('warehouse_staff');

        $this->getJson('/api/settings')
            ->assertOk()
            ->assertJsonPath('toman_per_1000_dinar', 120000);
    }

    public function test_legacy_large_value_is_migrated(): void
    {
        AppSetting::put('almanahel.toman_to_dinar_rate', 115000);

        $this->actingAsRole('admin');

        $this->getJson('/api/settings')
            ->assertOk()
            ->assertJsonPath('toman_per_1000_dinar', 115000);

        $this->assertSame(115000.0, (float) AppSetting::get('almanahel.toman_per_1000_dinar', 0));
    }
}
