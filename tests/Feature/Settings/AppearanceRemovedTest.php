<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppearanceRemovedTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_appearance_settings_page_no_longer_exists()
    {
        $this->actingAs(User::factory()->create())
            ->get('/settings/appearance')
            ->assertNotFound();
    }

    public function test_guests_get_no_appearance_page_either()
    {
        $this->get('/settings/appearance')->assertNotFound();
    }
}
