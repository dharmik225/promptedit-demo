<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\Generation;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_registered_users_are_clients(): void
    {
        $this->post('/register', [
            'name' => 'New Client',
            'email' => 'new@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'role' => 'admin',
        ]);

        $this->assertSame(UserRole::Client, User::query()->where('email', 'new@example.com')->sole()->role);
    }

    public function test_clients_cannot_open_the_admin_page(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/admin/generations')
            ->assertForbidden();
    }

    public function test_admin_sees_every_clients_generations_and_totals(): void
    {
        Generation::factory()->succeeded()->create();
        Generation::factory()->failed()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->get('/admin/generations')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('admin/Generations')
                ->has('generations.data', 2)
                ->has('generations.data.0.user.email')
                ->where('summary.total_generations', 2)
                ->where('summary.succeeded', 1)
                ->where('summary.failed', 1)
                ->where('summary.provider_cost', '0.056560000000')
                ->where('summary.revenue', '0.070700000000')
                ->where('summary.margin', '0.014140000000'));
    }

    public function test_admin_can_filter_by_client_email(): void
    {
        $client = User::factory()->create(['email' => 'findme@example.com']);
        Generation::factory()->for($client)->create();
        Generation::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->get('/admin/generations?search=findme')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('generations.data', 1)
                ->where('generations.data.0.user.email', 'findme@example.com'));
    }

    public function test_admin_is_sent_from_client_pages_to_analytics(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/dashboard')->assertRedirect('/admin/generations');
        $this->actingAs($admin)->get('/generations')->assertRedirect('/admin/generations');
        $this->actingAs($admin)->post('/generations', ['prompt' => 'x'])->assertRedirect('/admin/generations');

        $this->assertDatabaseCount('generations', 0);
    }

    public function test_admin_lands_on_analytics_after_login(): void
    {
        $admin = User::factory()->admin()->create();

        $this->post('/login', ['email' => $admin->email, 'password' => 'password'])
            ->assertRedirect('/dashboard');

        $this->get('/dashboard')->assertRedirect('/admin/generations');
    }

    public function test_admin_can_view_any_generation(): void
    {
        $generation = Generation::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->get("/generations/{$generation->uuid}")
            ->assertOk();
    }

    public function test_seeder_creates_admin_and_client_from_config(): void
    {
        config([
            'site.admin' => ['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'master-secret'],
            'site.test_client' => ['name' => 'Client', 'email' => 'client@example.com', 'password' => 'client-secret'],
        ]);

        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', 'admin@example.com')->sole();
        $client = User::query()->where('email', 'client@example.com')->sole();

        $this->assertSame(UserRole::Admin, $admin->role);
        $this->assertTrue(Hash::check('master-secret', $admin->password));
        $this->assertNotNull($admin->email_verified_at);
        $this->assertSame(UserRole::Client, $client->role);
        $this->assertDatabaseCount('users', 2);
    }

    public function test_seeder_skips_accounts_without_a_password(): void
    {
        config([
            'site.admin' => ['name' => 'Admin', 'email' => 'admin@example.com', 'password' => null],
            'site.test_client' => ['name' => 'Client', 'email' => null, 'password' => null],
        ]);

        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('users', 0);
    }
}
