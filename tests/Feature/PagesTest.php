<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Smoke test: every main page opens for the administrator (catches the "500" errors before they reach the server). */
class PagesTest extends TestCase
{
    public static function pages(): array
    {
        return array_map(fn ($u) => [$u], ['/', '/account', '/numbers', '/forms/ptr', '/forms/ptr/new', '/forms/jar/new', '/projects', '/projects/1', '/projects/new',
            '/receipts', '/reports', '/reports/documents', '/reports/receipts', '/reports/numbers', '/reports/budget-lines', '/budget-lines',
            '/admin/rates', '/admin/audit', '/admin/audit?verify=1', '/admin/users', '/admin/roles', '/admin/settings', '/admin/forms', '/review', '/alerts']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('pages')]
    public function test_page_opens(string $url): void
    {
        $this->actingAs($this->admin())->get($url)->assertOk();
    }

    public function test_guests_go_to_the_login(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/login')->assertOk();
    }

    /** The live 500 of 16-11-2026: no ended project + an open project without agreement amount. */
    public function test_dashboard_without_ended_projects(): void
    {
        \App\Models\Project::query()->update(['status' => 'Ongoing', 'status_mode' => 'manual', 'end_date' => now()->addYear()]);
        \App\Models\Project::query()->limit(1)->update(['budget_total' => null, 'finance_officer_id' => null]);
        $this->actingAs($this->admin())->get('/')->assertOk();
        // the page hides a broken tile (try/catch): check the tile itself
        $m = new \ReflectionMethod(\App\Http\Controllers\DashboardController::class, 'projects');
        $out = $m->invoke(app(\App\Http\Controllers\DashboardController::class));
        $this->assertGreaterThan(0, $out['missing']);
    }
}
