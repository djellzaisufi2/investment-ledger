<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreClientRequest;
use App\Models\Client;
use App\Services\LedgerService;

class ClientController extends Controller
{
    public function __construct(private LedgerService $ledger)
    {
    }

    public function index()
    {
        return Client::orderBy('name')->get();
    }

    public function store(StoreClientRequest $request)
    {
        $client = Client::create($request->validated());

        return response()->json($client, 201);
    }

    public function show(Client $client)
    {
        return $client;
    }

    public function balance(Client $client)
    {
        return response()->json([
            'client_id' => $client->id,
            'cash_balance' => $this->ledger->cashBalance($client),
        ]);
    }

    public function holdings(Client $client)
    {
        return response()->json([
            'client_id' => $client->id,
            'holdings' => $this->ledger->holdings($client),
        ]);
    }

    public function movements(Client $client)
    {
        return $client->movements()->orderBy('created_at')->get();
    }
}
