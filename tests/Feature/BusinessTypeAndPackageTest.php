<?php

namespace Tests\Feature;

use Database\Seeders\BusinessTypeSeeder;
use Database\Seeders\PackageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessTypeAndPackageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([BusinessTypeSeeder::class, PackageSeeder::class]);
    }

    public function test_can_list_business_types(): void
    {
        $response = $this->getJson('/api/v1/business-types');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(3, 'data');
    }

    public function test_can_filter_packages_by_business_type_code(): void
    {
        $response = $this->getJson('/api/v1/packages?business_type_code=pos');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data');

        foreach ($response->json('data') as $pkg) {
            $this->assertEquals('pos', $pkg['business_type']['code']);
        }
    }
}
