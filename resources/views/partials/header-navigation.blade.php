@foreach ($headerNavigation as $item)
    @php
        $url = $item->full_url ?: '#';
        $active = $item->route_name
            ? Request::routeIs($item->route_name)
            : request()->is(ltrim(parse_url($url, PHP_URL_PATH) ?: '/', '/'));
        $isServices = trim(parse_url($url, PHP_URL_PATH) ?: '', '/') === 'services';
        $children = $item->children;
        $hasCmsServices = $isServices && isset($headerServices) && $headerServices->isNotEmpty();
        $hasChildren = $children->isNotEmpty() || $hasCmsServices;
    @endphp
    <li class="{{ $active ? ($themeHeader === 'default' ? 'active' : 'is-active') : '' }} {{ $hasChildren ? ($themeHeader === 'default' ? 'menu-item-has-children' : "{$themeHeader}-navigation__services") : '' }}">
        @if ($hasChildren && in_array($themeHeader, ['t3', 't4'], true))
            <details>
                <summary>{{ $item->title }} <i class="bi bi-chevron-down" aria-hidden="true"></i></summary>
                <ul @class(['t3-navigation__submenu' => $themeHeader === 't3', 'sub-menu scrollable-submenu' => $themeHeader === 't2'])>
                    @if ($children->isNotEmpty())
                        @foreach ($children as $child)
                            <li><a href="{{ $child->full_url ?: '#' }}" target="{{ $child->target }}">{{ $child->title }}</a></li>
                        @endforeach
                    @else
                        @foreach ($headerServices as $service)
                            <li><a href="{{ route('cms.show', ['contentType' => 'services', 'content' => $service->slug]) }}">{{ $service->title }}</a></li>
                        @endforeach
                    @endif
                </ul>
            </details>
        @else
            <a href="{{ $url }}" target="{{ $item->target }}" @class(['drop-down' => $hasChildren])>{{ $item->title }} @if($hasChildren)<i class="bi bi-caret-down-fill"></i>@endif</a>
            @if ($hasChildren)
                <i class="bi bi-plus dropdown-icon"></i>
                <ul class="sub-menu scrollable-submenu">
                    @if ($children->isNotEmpty())
                        @foreach ($children as $child)
                            <li><a href="{{ $child->full_url ?: '#' }}" target="{{ $child->target }}">{{ $child->title }}</a></li>
                        @endforeach
                    @else
                        @foreach ($headerServices as $service)
                            <li><a href="{{ route('cms.show', ['contentType' => 'services', 'content' => $service->slug]) }}">{{ $service->title }}</a></li>
                        @endforeach
                    @endif
                </ul>
            @endif
        @endif
    </li>
@endforeach
