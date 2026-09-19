@php
    $categories = collect($section['vehicles'])->map(fn ($vehicle) => data_get($vehicle, 'category.name.name'))
        ->filter()->unique()->values();
    $bookingUrl = route('home', array_filter([
        'service_type' => $section['service_type'],
        'duration_days' => $section['duration_days'],
        'package_id' => $section['package_id'] ?? null,
    ])) . '#home-booking';
@endphp
<section class="home-vehicle-section" aria-labelledby="home-vehicle-title-{{ $sectionIndex }}" data-vehicle-section>
    <div class="container">
        <div class="home-vehicle-section__head">
            <div>
                @if ($section['eyebrow']) <span class="home-vehicle-section__eyebrow">{{ $section['eyebrow'] }}</span> @endif
                <h2 id="home-vehicle-title-{{ $sectionIndex }}">{{ $section['title'] }}</h2>
                @if ($section['description']) <p>{{ $section['description'] }}</p> @endif
            </div>
            <div class="home-vehicle-section__filters" role="group" aria-label="Filter {{ $section['title'] }} vehicles">
                <button type="button" class="is-active" aria-pressed="true" data-vehicle-category="">All Vehicles</button>
                @foreach ($categories as $category)
                    <button type="button" aria-pressed="false" data-vehicle-category="{{ $category }}">{{ $category }}</button>
                @endforeach
            </div>
            <a class="home-vehicle-section__link" href="{{ $bookingUrl }}">Search {{ $section['title'] }} <span aria-hidden="true">→</span></a>
        </div>
        <div class="home-vehicle-section__grid">
            @foreach ($section['vehicles'] as $vehicle)
                @php
                    $thumbnail = data_get($vehicle, 'thumbnail.path') ?: data_get($vehicle, 'thumbnail.0') ?: data_get($vehicle, 'images.0.path') ?: data_get($vehicle, 'images.0');
                    $amount = (float) data_get($vehicle, 'pricing_info.total_amount', 0);
                @endphp
                <article class="home-vehicle-section__card" data-category="{{ data_get($vehicle, 'category.name.name', '') }}">
                    <a href="{{ $bookingUrl }}" aria-label="Search {{ $vehicle['name'] }}">
                        <img src="{{ $thumbnail && is_string($thumbnail) ? s3_asset($thumbnail) : asset('assets/img/default-vehicle.jpg') }}" alt="{{ $vehicle['name'] }}" loading="lazy">
                        <div class="home-vehicle-section__body">
                            <h3>{{ $vehicle['name'] }}</h3>
                            <span>{{ data_get($vehicle, 'category.name.name', $section['title']) }}</span>
                            <div class="home-vehicle-section__specs">
                                @if (!empty($vehicle['seating_capacity'])) <span>{{ $vehicle['seating_capacity'] }} seats</span> @endif
                            </div>
                            <div class="home-vehicle-section__price">
                                @if ($amount > 0)
                                    <small>Indicative from</small> {{ getCurrencySymbol() }} {{ number_format(convertPrice($amount), 0) }}
                                    <small>/ {{ $section['duration_days'] }} {{ \Illuminate\Support\Str::plural('day', $section['duration_days']) }}</small>
                                @else
                                    <small>Search for a quote</small>
                                @endif
                            </div>
                        </div>
                    </a>
                </article>
            @endforeach
        </div>
    </div>
</section>
