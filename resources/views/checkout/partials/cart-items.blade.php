                                            <ul>
                                                @foreach ($cart as $key => $item)
                                                    @include('checkout.partials.cart-item', ['key' => $key, 'item' => $item])
                                                @endforeach
                                            </ul>
