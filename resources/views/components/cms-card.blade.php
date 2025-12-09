@props([
    'item',
    'delayMs' => 200,
    'type' => 'blog',
    'showPrice' => false,
    'showDuration' => false,
    'showRating' => false,
])

@php
    // Use pre-computed values from controller to avoid expensive operations in template
    // Handle both stdClass objects and arrays
    $title = (is_object($item) ? $item->title : $item['title']) ?? 'No title available';
    $currency = (is_object($item) ? $item->price_currency : $item['price_currency']) ?? 'USD';
    $rating = (int)((is_object($item) ? $item->rating : $item['rating']) ?? 0);
    $reviewsCount = (int)((is_object($item) ? $item->reviews_count : $item['reviews_count']) ?? 0);
    $isSpecialOffer = (bool)((is_object($item) ? $item->special_offer : $item['special_offer']) ?? false);
    $discount = (int)((is_object($item) ? $item->discount_percentage : $item['discount_percentage']) ?? 0);
    
    // These are now pre-computed in HomeController::getCmsContentByTypeSlug()
    $imageUrl = is_object($item) ? $item->imageUrl : $item['imageUrl'];
    $detailLink = is_object($item) ? $item->detailLink : $item['detailLink'];
    $categoryLink = is_object($item) ? $item->categoryLink : $item['categoryLink'];
    $formattedPrice = is_object($item) ? $item->formattedPrice : $item['formattedPrice'];
    $excerpt = is_object($item) ? $item->displayExcerpt : $item['displayExcerpt'];
    $date = is_object($item) ? $item->displayDate : $item['displayDate'];
    $location = (is_object($item) ? $item->location : $item['location']) ?? '';
    $category = (is_object($item) ? $item->category : $item['category']) ?? '';
    $duration = is_object($item) ? $item->duration : $item['duration'];
@endphp

<div class="col-lg-4 col-md-6 wow animate fadeInDown" data-wow-delay="{{ $delayMs }}ms" data-wow-duration="1500ms">
    <div class="blog-card2 two">
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
            @if ($showPrice && $formattedPrice)
                <div class="price-info">
                    <span class="price">{{ $currency }} {{ $formattedPrice }}</span>
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

            <a href="{{ $detailLink }}" class="blog-date">{{ $date }}</a>

            <h4><a href="{{ $detailLink }}">{{ $title }}</a></h4>

            <p>{{ $excerpt }}</p>

            @if ($category)
                <div class="category-tag">
                    <a href="{{ $categoryLink }}" class="category">{{ $category }}</a>
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
        font-size: 12px;
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
        font-size: 16px;
    }

    .duration {
        color: #666;
        font-size: 14px;
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
</style>
