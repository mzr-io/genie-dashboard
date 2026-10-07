<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Page components are resolved from the Vite manifest, which a build may not have refreshed.
        $this->withoutVite();
    }

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get(route('overview'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('overview'));
        $response->assertOk();
    }

    public function test_the_dashboard_route_name_is_an_alias_of_the_overview()
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('dashboard'))->assertRedirect(route('overview', absolute: false));
    }
}
