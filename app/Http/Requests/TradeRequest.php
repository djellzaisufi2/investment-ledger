<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TradeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'instrument' => ['required', 'string', 'max:50'],
            'quantity' => ['required', 'integer', 'min:1'],
            'price_per_unit' => ['required', 'numeric', 'min:0.01'],
        ];
    }
}
