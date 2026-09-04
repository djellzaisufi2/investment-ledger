<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Movement extends Model
{
    use HasFactory;

    public $timestamps = true;
    const UPDATED_AT = null;

    protected $fillable = [
        'client_id',
        'type',
        'amount',
        'instrument',
        'quantity',
        'price_per_unit',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'price_per_unit' => 'decimal:4',
        'quantity' => 'integer',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
