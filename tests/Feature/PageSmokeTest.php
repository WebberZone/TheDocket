<?php

namespace Tests\Feature;

use App\Models\CashFlow;
use App\Models\Expense;
use App\Models\Income;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Renders every page once so a Blade error can't hide from the suite.
 */
class PageSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_pages_render(): void
    {
        $user = User::factory()->create();
        $expense = Expense::factory()->create();
        $income = Income::factory()->create();
        $cashFlow = CashFlow::factory()->create();

        $pages = [
            '/dashboard',
            '/expenses',
            '/expenses/create',
            "/expenses/{$expense->id}",
            "/expenses/{$expense->id}/edit",
            '/income',
            '/income/create',
            "/income/{$income->id}",
            "/income/{$income->id}/edit",
            '/cashflows',
            '/cashflows/create',
            "/cashflows/{$cashFlow->id}",
            "/cashflows/{$cashFlow->id}/edit",
            '/reports',
            '/balances',
            '/ledger',
            '/ledger?payment_method_id='.$expense->payment_method_id.'&currency=GBP',
            '/capture',
            '/profile',
            '/settings',
        ];

        foreach ($pages as $page) {
            $this->actingAs($user)->get($page)->assertOk();
        }
    }
}
