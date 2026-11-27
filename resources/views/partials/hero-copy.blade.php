@if (!empty($slide['eyebrow']))
    <span class="shared-hero-eyebrow"><span class="shared-hero-eyebrow__dot" aria-hidden="true"></span>{{ $slide['eyebrow'] }}</span>
@endif
@if ($index === 0)
    <h1 class="shared-hero-heading">{{ $slide['heading'] }}</h1>
@else
    <h2 class="shared-hero-heading">{{ $slide['heading'] }}</h2>
@endif
@if ($slide['subheading'] !== '')
    <p class="shared-hero-description">{{ $slide['subheading'] }}</p>
@endif
@if ($slide['caption'] !== '')
    <small class="shared-hero-caption">{{ $slide['caption'] }}</small>
@endif
<div class="shared-hero-actions">
    @if (($slide['ctaText'] ?? '') !== '')
        <a class="shared-hero-action" href="{{ $slide['ctaUrl'] }}"><span>{{ $slide['ctaText'] }}</span> <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
    @endif
    <a class="shared-hero-action-secondary" href="{{ route('vehicles') }}"><span>View Fleet</span></a>
</div>
@if (!empty($slide['locationLabel']))
    <span class="shared-hero-location"><i class="bi bi-geo-alt-fill" aria-hidden="true"></i> <span>{{ $slide['locationLabel'] }}</span></span>
@endif
