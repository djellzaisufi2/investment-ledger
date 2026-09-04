<?php

namespace App\Services;

use App\Exceptions\InvalidMovementException;
use App\Models\Client;
use App\Models\Movement;
use Illuminate\Support\Facades\DB;

class LedgerService
{
    public function deposit(Client $client, float $amount): Movement
    {
        $this->assertPositive($amount, 'Shuma e depozitimit');

        return DB::transaction(function () use ($client, $amount) {
            $this->lock($client);

            return Movement::create([
                'client_id' => $client->id,
                'type' => 'deposit',
                'amount' => $amount,
            ]);
        });
    }

    public function withdraw(Client $client, float $amount): Movement
    {
        $this->assertPositive($amount, 'Shuma e terheqjes');

        return DB::transaction(function () use ($client, $amount) {
            $locked = $this->lock($client);
            $cash = $this->cashBalance($locked);

            if ($amount > $cash) {
                throw new InvalidMovementException(
                    "Nuk mund te terhiqet {$amount}, klienti ka vetem {$cash} ne llogari."
                );
            }

            return Movement::create([
                'client_id' => $client->id,
                'type' => 'withdraw',
                'amount' => -$amount,
            ]);
        });
    }

    public function buy(Client $client, string $instrument, int $quantity, float $pricePerUnit): Movement
    {
        $this->assertPositive($quantity, 'Numri i pjeseve');
        $this->assertPositive($pricePerUnit, 'Cmimi per pjese');

        $total = round($quantity * $pricePerUnit, 2);

        return DB::transaction(function () use ($client, $instrument, $quantity, $pricePerUnit, $total) {
            $locked = $this->lock($client);
            $cash = $this->cashBalance($locked);

            if ($total > $cash) {
                throw new InvalidMovementException(
                    "Nuk mund te blihen {$quantity} pjese te {$instrument} per {$total}, klienti ka vetem {$cash} ne llogari."
                );
            }

            return Movement::create([
                'client_id' => $client->id,
                'type' => 'buy',
                'amount' => -$total,
                'instrument' => $instrument,
                'quantity' => $quantity,
                'price_per_unit' => $pricePerUnit,
            ]);
        });
    }

    public function sell(Client $client, string $instrument, int $quantity, float $pricePerUnit): Movement
    {
        $this->assertPositive($quantity, 'Numri i pjeseve');
        $this->assertPositive($pricePerUnit, 'Cmimi per pjese');

        $total = round($quantity * $pricePerUnit, 2);

        return DB::transaction(function () use ($client, $instrument, $quantity, $pricePerUnit, $total) {
            $locked = $this->lock($client);
            $held = $this->holdingsFor($locked, $instrument);

            if ($quantity > $held) {
                throw new InvalidMovementException(
                    "Nuk mund te shiten {$quantity} pjese te {$instrument}, klienti zoteron vetem {$held}."
                );
            }

            return Movement::create([
                'client_id' => $client->id,
                'type' => 'sell',
                'amount' => $total,
                'instrument' => $instrument,
                'quantity' => $quantity,
                'price_per_unit' => $pricePerUnit,
            ]);
        });
    }

    public function cashBalance(Client $client): float
    {
        return (float) $client->movements()->sum('amount');
    }

    public function holdings(Client $client): array
    {
        $rows = $client->movements()
            ->whereIn('type', ['buy', 'sell'])
            ->selectRaw("instrument, SUM(CASE WHEN type = 'buy' THEN quantity ELSE -quantity END) as net")
            ->groupBy('instrument')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            if ((int) $row->net > 0) {
                $result[$row->instrument] = (int) $row->net;
            }
        }

        return $result;
    }

    private function holdingsFor(Client $client, string $instrument): int
    {
        return $this->holdings($client)[$instrument] ?? 0;
    }

    private function lock(Client $client): Client
    {
        return Client::where('id', $client->id)->lockForUpdate()->firstOrFail();
    }

    private function assertPositive(float $value, string $label): void
    {
        if ($value <= 0) {
            throw new InvalidMovementException("{$label} duhet te jete pozitive (me e madhe se 0).");
        }
    }
}
