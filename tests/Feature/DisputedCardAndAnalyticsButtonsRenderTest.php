<?php

use App\Models\User;

it('renders the Disputed KPI card and the Analytics download/print buttons on the full dashboard load', function () {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->get(route('admin.dashboard'));

    $response->assertOk()
        ->assertSee('Disputed')
        ->assertSee('downloadAnalyticsCsv()', false)
        ->assertSee('printAnalyticsPanel()', false);
});
