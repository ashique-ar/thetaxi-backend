@props([
    'item' => null,
    'delayMs' => 200,
    'type' => 'blog',
    'showPrice' => false,
    'showDuration' => false,
    'showRating' => false,
])

@php
    // Extract data from item (handles both object and array)
    if (is_object($item)) {
        $title = $item->title ?? 'No title';
        $location = $item->location ?? '';
        $category = $item->category ?? '';
        $excerpt = $item->excerpt ?? substr(strip_tags($item->body ?? ''), 0, 100);
        $itemPrice = $item->price ?? null;
        $currency = $item->price_currency ?? 'USD';
        $duration = $item->duration ?? null;
        $rating = (int) ($item->rating ?? 0);
        $reviewsCount = (int) ($item->reviews_count ?? 0);
        $isSpecialOffer = (bool) ($item->special_offer ?? false);
        $discount = (int) ($item->discount_percentage ?? 0);
        $publishedDate = $item->published_at ?? ($item->created_at ?? null);
        $thumbnail = $item->thumbnail ?? null;
        $slug = $item->slug ?? '#';
        $pickupLocation = $item->pickup_location ?? null;
        $dropoffLocation = $item->dropoff_location ?? null;
        $minDays = $item->min_days ?? null;
        $serviceType = $item->service_type ?? null;
    } else {
        // Array fallback
        $title = $item['title'] ?? 'No title';
        $location = $item['location'] ?? '';
        $category = $item['category'] ?? '';
        $excerpt = $item['excerpt'] ?? substr(strip_tags($item['body'] ?? ''), 0, 100);
        $itemPrice = $item['price'] ?? null;
        $currency = $item['price_currency'] ?? 'USD';
        $duration = $item['duration'] ?? null;
        $rating = (int) ($item['rating'] ?? 0);
        $reviewsCount = (int) ($item['reviews_count'] ?? 0);
        $isSpecialOffer = (bool) ($item['special_offer'] ?? false);
        $discount = (int) ($item['discount_percentage'] ?? 0);
        $publishedDate = $item['published_at'] ?? ($item['created_at'] ?? null);
        $thumbnail = $item['thumbnail'] ?? null;
        $slug = $item['slug'] ?? '#';
        $pickupLocation = $item['pickup_location'] ?? null;
        $dropoffLocation = $item['dropoff_location'] ?? null;
        $minDays = $item['min_days'] ?? null;
        $serviceType = $item['service_type'] ?? null;
    }

    // Format price
    $price = $itemPrice ? number_format(floor(max(0, $itemPrice)), 0) : null;

    // Format date
    $date = $publishedDate
        ? (is_object($publishedDate)
            ? $publishedDate->format('d F, Y')
            : \Carbon\Carbon::parse($publishedDate)->format('d F, Y'))
        : 'N/A';

    // Generate URLs
    $imageUrl = $thumbnail && s3_asset($thumbnail) ? s3_asset($thumbnail) : asset('assets/img/default-blog.jpg');
    $detailLink = route('cms.show', ['contentType' => $type, 'content' => $slug]);
    $categoryLink = $category
        ? route('cms.index', ['contentType' => $type]) . '?category=' . urlencode($category)
        : '#';
@endphp

<div class="col-lg-3 col-md-4 col-sm-6 wow animate fadeInDown" data-wow-delay="{{ $delayMs }}ms" data-wow-duration="1500ms">
    <div class="blog-card2 two cms-content-card">
        <div class="blog-img-wrap">
            <a href="{{ $detailLink }}" class="blog-img">
                <img src="{{ $imageUrl }}" alt="{{ $title }}" loading="lazy">
                @if ($isSpecialOffer && $discount > 0)
                    <div class="discount-badge">-{{ $discount }}%</div>
                @endif
            </a>

            @if ($location)
                <a href="{{ $categoryLink }}" class="location">
                    <svg width="14" height="14" viewBox="0 0 14 14" xmlns="http://www.w3.org/2000/svg">
                        <path
                            d="M6.83615 0C3.77766 0 1.28891 2.48879 1.28891 5.54892C1.28891 7.93837 4.6241 11.8351 6.05811 13.3994C6.25669 13.6175 6.54154 13.7411 6.83615 13.7411C7.13076 13.7411 7.41561 13.6175 7.6142 13.3994C9.04821 11.8351 12.3834 7.93833 12.3834 5.54892C12.3834 2.48879 9.89464 0 6.83615 0ZM7.31469 13.1243C7.18936 13.2594 7.02008 13.3342 6.83615 13.3342C6.65222 13.3342 6.48295 13.2594 6.35761 13.1243C4.95614 11.5959 1.69584 7.79515 1.69584 5.54896C1.69584 2.7134 4.00067 0.406933 6.83615 0.406933C9.67164 0.406933 11.9765 2.7134 11.9765 5.54896C11.9765 7.79515 8.71617 11.5959 7.31469 13.1243Z" />
                        <path
                            d="M6.83618 8.54529C8.4624 8.54529 9.7807 7.22698 9.7807 5.60077C9.7807 3.97456 8.4624 2.65625 6.83618 2.65625C5.20997 2.65625 3.89166 3.97456 3.89166 5.60077C3.89166 7.22698 5.20997 8.54529 6.83618 8.54529Z" />
                    </svg>
                    {{ $location }}
                </a>
            @endif
        </div>

        <div class="blog-content">
            @if ($showPrice && $price)
                <div class="price-info">
                    <span class="price">{{ $currency }} {{ $price }}</span>
                    @if ($duration)
                        <span class="duration">/ {{ $duration }}</span>
                    @endif
                </div>
            @endif

            @if ($showRating && $rating > 0)
                <div class="rating-info">
                    <div class="stars">
                        @for ($i = 1; $i <= 5; $i++)
                            <span class="star {{ $i <= $rating ? 'filled' : '' }}">★</span>
                        @endfor
                    </div>
                    @if ($reviewsCount > 0)
                        <span class="reviews">({{ $reviewsCount }} reviews)</span>
                    @endif
                </div>
            @endif
            <h4><a href="{{ $detailLink }}">{{ $title }}</a></h4>

            <p>{{ $excerpt }}</p>

            @if ($category)
                <div class="category-tag">
                    <a href="{{ $categoryLink }}" class="category">{{ $category }}</a>
                </div>
            @endif

            @if ($pickupLocation)
                <div class="booking-info-mini mt-3 pt-2 border-top">
                    @if ($pickupLocation)
                        <small class="d-block text-muted mb-1"><i class="bi bi-geo-alt"></i> Pickup:
                            {{ Str::limit($pickupLocation, 20) }}</small>
                    @endif
                    @if (!empty($item->min_days))
                        <small class="d-block text-muted"><i class="bi bi-calendar-event"></i> Min Duration:
                            {{ $item->min_days }} day{{ $item->min_days != 1 ? 's' : '' }}</small>
                    @endif
                </div>
            @endif
        </div>
    </div>
</div>

<style>
    .discount-badge {
        position: absolute;
        top: 10px;
        right: 10px;
        background: #dc3545;
        color: white;
        padding: 5px 10px;
        border-radius: 15px;
        font-size: 11px;
        font-weight: bold;
        z-index: 2;
    }

    .price-info {
        display: flex;
        align-items: center;
        gap: 5px;
        margin-bottom: 10px;
    }

    .price {
        font-weight: bold;
        color: #c91c23;
        font-size: 14px;
    }

    .duration {
        color: #666;
        font-size: 12px;
    }

    .rating-info {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 10px;
    }

    .stars {
        display: flex;
        gap: 2px;
    }

    .star {
        color: #ddd;
        font-size: 14px;
    }

    .star.filled {
        color: #ffc107;
    }

    .reviews {
        font-size: 12px;
        color: #666;
    }

    .category-tag {
        margin-top: 10px;
    }

    .category {
        background: #f8f9fa;
        color: #6c757d;
        padding: 3px 8px;
        border-radius: 12px;
        font-size: 12px;
        text-decoration: none;
        transition: all 0.3s ease;
    }

    .category:hover {
        background: #c91c23;
        color: white;
    }

    .cms-content-card {
        height: 100%;
        border: 1px solid rgba(17, 24, 39, 0.08);
        border-radius: 8px;
        overflow: hidden;
        background: #fff;
        box-shadow: 0 16px 42px rgba(15, 23, 42, 0.08);
        transition: transform 0.25s ease, box-shadow 0.25s ease, border-color 0.25s ease;
    }

    .cms-content-card:hover {
        transform: translateY(-6px);
        border-color: rgba(191, 38, 41, 0.28);
        box-shadow: 0 24px 60px rgba(15, 23, 42, 0.14);
    }

    .cms-content-card .blog-img-wrap {
        position: relative;
        aspect-ratio: 16 / 10;
        background: #f3f4f6;
    }

    .cms-content-card .blog-img,
    .cms-content-card .blog-img img {
        display: block;
        width: 100%;
        height: 100%;
    }

    .cms-content-card .blog-img img {
        object-fit: cover;
        transition: transform 0.35s ease;
    }

    .cms-content-card:hover .blog-img img {
        transform: scale(1.04);
    }

    .cms-content-card .location {
        left: 14px;
        bottom: 14px;
        max-width: calc(100% - 28px);
        border-radius: 999px;
        backdrop-filter: blur(12px);
        background: rgba(255, 255, 255, 0.92);
        color: #111827;
        box-shadow: 0 10px 24px rgba(15, 23, 42, 0.18);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        font-size: 12px;
    }

    .cms-content-card .blog-content {
        display: flex;
        flex-direction: column;
        min-height: 190px;
        padding: 16px;
    }

    .cms-content-card .blog-content h4 {
        margin-bottom: 8px;
        font-size: 18px;
        line-height: 1.25;
    }

    .cms-content-card .blog-content h4 a {
        color: #111827;
        transition: color 0.2s ease;
    }

    .cms-content-card:hover .blog-content h4 a {
        color: var(--primary-color1, #BF2629);
    }

    .cms-content-card .blog-content p {
        color: #64748b;
        font-size: 13px;
        line-height: 1.55;
        display: -webkit-box;
        -webkit-line-clamp: 3;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    .cms-content-card .category-tag {
        margin-top: auto;
        padding-top: 10px;
    }

    .cms-content-card .price-info {
        padding: 6px 10px;
        width: fit-content;
        border-radius: 999px;
        background: rgba(191, 38, 41, 0.08);
    }

    .cms-content-card .category {
        font-size: 11px;
    }

    .cms-content-card .booking-info-mini small {
        font-size: 12px;
        line-height: 1.35;
    }

    @media (max-width: 575px) {
        .cms-content-card .blog-content {
            min-height: 0;
            padding: 18px;
        }
    }
</style>
