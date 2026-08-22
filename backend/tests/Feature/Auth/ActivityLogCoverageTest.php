<?php

namespace Tests\Feature\Auth;

use App\Models\ActivityLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesDomainData;
use Tests\TestCase;

/** @group auth */
class ActivityLogCoverageTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDomainData;

    public function test_customer_create_and_update_capture_actor_request_and_change_details(): void
    {
        $branch = $this->makeBranch();
        $this->actingAsRole('admin', $branch);

        $customer = $this->postJson('/api/customers', [
            'name' => 'همکار آزمایشی',
            'phone' => '09120000000',
            'branch_id' => $branch->id,
        ])->assertCreated()->json();

        $created = ActivityLog::query()->where('module', 'customers')->where('action', 'created')->firstOrFail();
        $this->assertNotNull($created->event_uuid);
        $this->assertNotNull($created->request_id);
        $this->assertSame('POST', $created->http_method);
        $this->assertSame('api/customers', $created->route);
        $this->assertSame('info', $created->severity);
        $this->assertSame('همکار آزمایشی', $created->properties['after']['name']);

        $this->putJson('/api/customers/' . $customer['id'], ['phone' => '09350000000'])->assertOk();

        $updated = ActivityLog::query()->where('module', 'customers')->where('action', 'updated')->firstOrFail();
        $this->assertSame('09120000000', $updated->properties['before']['phone']);
        $this->assertSame('09350000000', $updated->properties['after']['phone']);
        $this->assertContains('phone', $updated->properties['changed_fields']);
    }

    public function test_mutation_fallback_records_successful_uninstrumented_route_once(): void
    {
        $this->actingAsRole('admin');

        $this->postJson('/api/notifications/read-all')->assertOk();

        $logs = ActivityLog::query()->where('module', 'notifications')->get();
        $this->assertCount(1, $logs);
        $this->assertTrue((bool) $logs->first()->properties['automatic']);
        $this->assertSame(200, $logs->first()->status_code);
    }

    public function test_admin_summary_uses_filters_and_non_admin_is_forbidden(): void
    {
        $branch = $this->makeBranch();
        $this->actingAsRole('admin', $branch);
        $this->postJson('/api/customers', ['name' => 'مشتری', 'branch_id' => $branch->id])->assertCreated();

        $this->getJson('/api/activity-logs/summary?module=customers')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('unique_users', 1)
            ->assertJsonPath('modules.0.module', 'customers');

        $this->actingAsRole('branch_manager', $branch);
        $this->getJson('/api/activity-logs/summary')->assertForbidden();
    }
}
