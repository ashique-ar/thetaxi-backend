<?php
// app/Http/Requests/Currency/UpdateCurrencyRequest.php
namespace App\Http\Requests\Currency;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCurrencyRequest extends FormRequest
{
    public function authorize() { return true; }

    public function rules()
    {
        $currencyId = $this->route('currency')->id;

        return [
            'code'       => ['sometimes','required','string','size:3',"unique:currencies,code,{$currencyId}"],
            'name'       => ['sometimes','required','string','max:255'],
            'symbol'     => ['sometimes','nullable','string','max:10'],
            'exrate'     => ['sometimes','nullable','numeric'],
            'country_id' => ['sometimes','nullable','exists:countries,id'],
        ];
    }
}