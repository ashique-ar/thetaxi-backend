@php
    $uniqueTerms = [];
    foreach ($termsByService as $service => $terms) {
        foreach ($terms as $term) {
            $uniqueTerms[$term->id] ??= ['term' => $term, 'services' => [], 'payment_types' => []];
            $uniqueTerms[$term->id]['services'][] = ucfirst(str_replace('_', ' ', $service));
        }
    }
    foreach ($termsByPaymentType as $type => $terms) {
        foreach ($terms as $term) {
            $uniqueTerms[$term->id] ??= ['term' => $term, 'services' => [], 'payment_types' => []];
            $uniqueTerms[$term->id]['payment_types'][] = $type;
        }
    }
    foreach ($uniqueTerms as &$termMeta) {
        $termMeta['services'] = array_values(array_unique($termMeta['services']));
        $termMeta['payment_types'] = array_values(array_unique($termMeta['payment_types']));
    }
    unset($termMeta);
@endphp
@if ($uniqueTerms)
    <section class="mt-5 border-t border-slate-100 pt-4" aria-labelledby="v2-terms-title">
        <h3 id="v2-terms-title" class="text-sm font-bold text-slate-900">Applicable Terms &amp; Conditions</h3>
        <div class="mt-3 max-h-64 space-y-3 overflow-y-auto rounded-xl border border-slate-200 bg-slate-50 p-4 text-xs text-slate-600">
            @foreach ($uniqueTerms as $meta)
                @php $term = $meta['term']; @endphp
                <div class="term-item border-b border-slate-200 pb-3 last:border-0 last:pb-0" data-payment-types="{{ implode(',', $meta['payment_types']) }}" data-term-id="{{ $term->id }}" data-term-version="{{ $term->version }}">
                    <div class="term-header flex items-start justify-between gap-3">
                        <div><strong class="text-slate-900">{{ $term->title }}</strong>
                            @if ($meta['services']) <small class="block text-slate-500">Applies to: {{ implode(', ', $meta['services']) }}</small> @endif
                            @if ($meta['payment_types']) <small class="block text-slate-500">Payment-specific: {{ implode(', ', $meta['payment_types']) }}</small> @endif
                        </div>
                        <button type="button" class="term-toggle shrink-0 font-semibold text-red-700" aria-expanded="true">Hide</button>
                    </div>
                    <div class="term-body mt-2 overflow-hidden break-words">{!! $term->content !!}</div>
                </div>
            @endforeach
        </div>
        <div id="termsAcceptancePanel" class="mt-4 rounded-xl border border-slate-200 p-3">
            <label for="accept_all_terms" class="flex items-start gap-3 text-xs text-slate-700"><input id="accept_all_terms" type="checkbox" aria-describedby="termsAcceptHelp" class="rounded border-slate-300 text-red-600 focus:ring-red-500"><span>I agree to the above <strong>Terms &amp; Conditions</strong><span id="termsAcceptHelp" class="mt-1 block text-slate-500">Required before placing your booking.</span></span></label>
            <div id="termsAcceptError" class="terms-accept-error mt-2 text-xs text-red-600" role="alert">Please tick this box to continue with your booking.</div>
            <div id="termsAcceptedHiddenInputs"></div>
        </div>
    </section>
@endif
