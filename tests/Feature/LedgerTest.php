<?php

namespace Tests\Feature;

use App\Exceptions\InvalidMovementException;
use App\Models\Client;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_follows_the_exact_scenario_from_the_assignment_brief(): void
    {
        $ledger = app(LedgerService::class);
        $ana = Client::create(['name' => 'Ana']);

        $ledger->deposit($ana, 1000);
        $this->assertEquals(1000.0, $ledger->cashBalance($ana));

        $ledger->buy($ana, 'AAPL', 5, 100);
        $this->assertEquals(500.0, $ledger->cashBalance($ana));
        $this->assertEquals(['AAPL' => 5], $ledger->holdings($ana));

        $ledger->sell($ana, 'AAPL', 3, 120);
        $this->assertEquals(860.0, $ledger->cashBalance($ana));
        $this->assertEquals(['AAPL' => 2], $ledger->holdings($ana));
    }

    public function test_it_rejects_a_withdrawal_larger_than_the_cash_balance_and_leaves_it_unchanged(): void
    {
        $ledger = app(LedgerService::class);
        $client = Client::create(['name' => 'Besa']);
        $ledger->deposit($client, 100);

        $this->expectException(InvalidMovementException::class);

        try {
            $ledger->withdraw($client, 500);
        } finally {
            $this->assertEquals(100.0, $ledger->cashBalance($client));
        }
    }

    public function test_it_rejects_selling_more_pieces_than_the_client_owns_and_leaves_holdings_unchanged(): void
    {
        $ledger = app(LedgerService::class);
        $client = Client::create(['name' => 'Driton']);
        $ledger->deposit($client, 1000);
        $ledger->buy($client, 'MSFT', 2, 300);

        $this->expectException(InvalidMovementException::class);

        try {
            $ledger->sell($client, 'MSFT', 5, 300);
        } finally {
            $this->assertEquals(['MSFT' => 2], $ledger->holdings($client));
            $this->assertEquals(400.0, $ledger->cashBalance($client));
        }
    }

    public function test_it_rejects_a_non_positive_amount(): void
    {
        $ledger = app(LedgerService::class);
        $client = Client::create(['name' => 'Test']);

        $this->expectException(InvalidMovementException::class);

        $ledger->deposit($client, -50);
    }

    public function test_it_creates_a_client_and_lists_it_via_the_api(): void
    {
        $this->postJson('/api/clients', ['name' => 'Api Test'])
            ->assertStatus(201)
            ->assertJsonFragment(['name' => 'Api Test']);

        $this->getJson('/api/clients')
            ->assertStatus(200)
            ->assertJsonFragment(['name' => 'Api Test']);
    }

    public function test_it_rejects_an_over_withdrawal_through_the_api_with_a_422_and_clear_message(): void
    {
        $client = Client::create(['name' => 'Api Withdraw']);
        app(LedgerService::class)->deposit($client, 100);

        $this->postJson("/api/clients/{$client->id}/withdraw", ['amount' => 999])
            ->assertStatus(422)
            ->assertJsonStructure(['message']);

        $this->getJson("/api/clients/{$client->id}/balance")
            ->assertJson(['cash_balance' => 100]);
    }
}
