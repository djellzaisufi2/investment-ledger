<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InvalidMovementException;
use App\Http\Controllers\Controller;
use App\Http\Requests\AmountRequest;
use App\Http\Requests\TradeRequest;
use App\Models\Client;
use App\Services\LedgerService;

class MovementController extends Controller
{
    public function __construct(private LedgerService $ledger)
    {
    }

    public function deposit(AmountRequest $request, Client $client)
    {
        return $this->handle(fn () => $this->ledger->deposit($client, (float) $request->amount));
    }

    public function withdraw(AmountRequest $request, Client $client)
    {
        return $this->handle(fn () => $this->ledger->withdraw($client, (float) $request->amount));
    }

    public function buy(TradeRequest $request, Client $client)
    {
        return $this->handle(fn () => $this->ledger->buy(
            $client,
            $request->instrument,
            (int) $request->quantity,
            (float) $request->price_per_unit
        ));
    }

    public function sell(TradeRequest $request, Client $client)
    {
        return $this->handle(fn () => $this->ledger->sell(
            $client,
            $request->instrument,
            (int) $request->quantity,
            (float) $request->price_per_unit
        ));
    }

    private function handle(callable $operation)
    {
        try {
            $movement = $operation();

            return response()->json($movement, 201);
        } catch (InvalidMovementException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}
