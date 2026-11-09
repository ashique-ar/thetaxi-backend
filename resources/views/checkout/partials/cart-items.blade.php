                                            <ul>
                                                @foreach ($cart as $key => $item)
                                                    @include('checkout.themes.cart-item', ['key' => $key, 'item' => $item])
                                                @endforeach
                                            </ul>
