@extends('layouts.app')

@section('title', 'Share your trip feedback')
@section('seo_exact_title', 'true')
@push('meta')
    @include('partials.seo', ['seoOverride' => ['is_indexable' => false]])
@endpush

@section('content')
<main class="container py-5" style="max-width: 760px">
    <section class="card border-0 shadow-sm">
        <div class="card-body p-4 p-md-5">
            <p class="text-muted mb-2">Booking {{ $booking->booking_number ?: $booking->id }}</p>
            @if ($booking->customer_rating !== null)
                <h1 class="h3 mb-3">Thank you for your feedback</h1>
                <p class="mb-0">Your rating has already been recorded. We appreciate you taking the time to share it.</p>
            @else
                <h1 class="h3 mb-2">How was your trip?</h1>
                <p class="text-muted mb-4">Your feedback helps us improve our taxi and rental service.</p>
                <form method="POST" action="{{ request()->fullUrl() }}">
                    @csrf
                    <fieldset class="mb-4">
                        <legend class="form-label">Choose a rating from 1 to 5</legend>
                        <div class="d-flex flex-wrap gap-3" role="radiogroup" aria-label="Trip rating">
                            @foreach (range(1, 5) as $rating)
                                <label class="d-flex align-items-center gap-2 border rounded px-3 py-2">
                                    <input type="radio" name="rating" value="{{ $rating }}" @checked((string) old('rating') === (string) $rating) required>
                                    <span>{{ $rating }} {{ $rating === 1 ? 'star' : 'stars' }}</span>
                                </label>
                            @endforeach
                        </div>
                        @error('rating')<div class="text-danger mt-2" role="alert">Please choose a rating.</div>@enderror
                    </fieldset>
                    <div class="mb-4">
                        <label for="comment" class="form-label">Anything you would like us to know? <span class="text-muted">(optional)</span></label>
                        <textarea id="comment" name="comment" class="form-control" rows="4" maxlength="1000">{{ old('comment') }}</textarea>
                        @error('comment')<div class="text-danger mt-2" role="alert">{{ $message }}</div>@enderror
                    </div>
                    <button class="btn btn-primary" type="submit">Send feedback</button>
                </form>
            @endif
        </div>
    </section>
</main>
@endsection
