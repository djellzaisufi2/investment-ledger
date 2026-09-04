<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Services\LedgerService;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(LedgerService $ledger): void
    {
        $ana = Client::create(['name' => 'Ana']);
        $ledger->deposit($ana, 1000);
        $ledger->buy($ana, 'AAPL', 5, 100);
        $ledger->sell($ana, 'AAPL', 3, 120);

        $besa = Client::create(['name' => 'Besa']);
        $ledger->deposit($besa, 500);
        $ledger->withdraw($besa, 150);

        $driton = Client::create(['name' => 'Driton']);
        $ledger->deposit($driton, 2000);
        $ledger->buy($driton, 'MSFT', 4, 300);
        $ledger->buy($driton, 'TSLA', 2, 250);
    }
}
