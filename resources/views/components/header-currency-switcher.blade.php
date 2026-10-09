@php
    $selectedCurrencyCode = getSelectedCurrency();
    $currencyChoices = getAvailableCurrencies();
@endphp
<details class="header-currency">
    <summary aria-label="Change currency, currently {{ $selectedCurrencyCode }}">
        <i class="bi bi-globe2" aria-hidden="true"></i>
        <span class="header-currency__selection">
            <span class="header-currency__label">Currency</span>
            <strong>{{ $selectedCurrencyCode }}</strong>
        </span>
        <i class="bi bi-chevron-down header-currency__arrow" aria-hidden="true"></i>
    </summary>
    <div class="header-currency__panel">
        <p>Choose your currency</p>
        <ul>
            @foreach ($currencyChoices as $currency)
                <li>
                    <a href="#" class="currency-option {{ $selectedCurrencyCode === $currency['code'] ? 'is-active' : '' }}"
                        data-currency="{{ $currency['code'] }}" aria-current="{{ $selectedCurrencyCode === $currency['code'] ? 'true' : 'false' }}">
                        <span>
                            <strong>{{ $currency['code'] }} ({{ $currency['symbol'] ?? $currency['code'] }})</strong>
                            <span class="header-currency__name">{{ $currency['name'] }}</span>
                        </span>
                        @if ($selectedCurrencyCode === $currency['code'])
                            <i class="bi bi-check-lg" aria-hidden="true"></i>
                        @endif
                    </a>
                </li>
            @endforeach
        </ul>
    </div>
</details>
