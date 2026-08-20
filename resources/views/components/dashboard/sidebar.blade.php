@php
    $currentRoute = request()->route()?->getName();
    $orderCount = \App\Models\Order::count();

    $navGroups = [
        [
            'label' => 'Overview',
            'items' => [
                ['icon' => 'dashboard', 'label' => 'Dashboard', 'route' => 'dashboard'],
            ],
        ],
        [
            'label' => 'Commerce',
            'items' => [
                ['icon' => 'orders', 'label' => 'Orders', 'route' => 'orders.index', 'badge' => $orderCount],
                ['icon' => 'products', 'label' => 'Products', 'route' => null],
                ['icon' => 'customers', 'label' => 'Customers', 'route' => null],
            ],
        ],
        [
            'label' => 'Insights',
            'items' => [
                ['icon' => 'analytics', 'label' => 'Analytics', 'route' => null],
                ['icon' => 'reports', 'label' => 'Reports', 'route' => null],
            ],
        ],
        [
            'label' => 'Management',
            'items' => [
                ['icon' => 'settings', 'label' => 'Settings', 'route' => null],
            ],
        ],
    ];
@endphp

<aside id="dashboard-sidebar"
    class="fixed inset-y-0 left-0 z-40 flex w-64 -translate-x-full flex-col border-r border-line bg-surface transition-transform duration-200 ease-out lg:static lg:translate-x-0">
    {{-- Brand --}}
    <div class="flex h-16 items-center justify-between border-b border-line px-5">
        <a href="{{ route('dashboard') }}" class="flex items-center gap-2.5">
            <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-ink text-surface">
                <x-dashboard.icon name="package" class="h-4.5 w-4.5" />
            </span>
            <span class="text-[15px] font-semibold tracking-tight text-ink">Storefront</span>
        </a>
        <button id="sidebar-close" type="button"
            class="rounded-md p-1.5 text-muted hover:bg-canvas hover:text-ink lg:hidden">
            <x-dashboard.icon name="close" class="h-5 w-5" />
        </button>
    </div>

    {{-- Navigation --}}
    <nav class="flex-1 space-y-6 overflow-y-auto px-3 py-5">
        @foreach ($navGroups as $group)
            <div>
                <p class="px-3 pb-2 text-[11px] font-medium uppercase tracking-[0.12em] text-faint">{{ $group['label'] }}</p>
                <ul class="space-y-1">
                    @foreach ($group['items'] as $item)
                        @php($isActive = $item['route'] !== null && $currentRoute === $item['route'])
                        <li>
                            <a href="{{ $item['route'] ? route($item['route']) : '#' }}"
                                class="group flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors {{ $isActive ? 'bg-ink text-surface' : 'text-muted hover:bg-canvas hover:text-ink' }}">
                                <x-dashboard.icon name="{{ $item['icon'] }}"
                                    class="h-[18px] w-[18px] {{ $isActive ? 'text-surface' : 'text-faint group-hover:text-ink' }}" />
                                <span class="flex-1">{{ $item['label'] }}</span>
                                @if (! empty($item['badge']))
                                    <span
                                        class="rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $isActive ? 'bg-surface/15 text-surface' : 'bg-canvas text-muted' }}">{{ $item['badge'] }}</span>
                                @endif
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </nav>

    {{-- Footer --}}
    <div class="space-y-3 border-t border-line p-3">
        <div class="rounded-xl border border-line bg-canvas p-3">
            <p class="text-xs font-semibold text-ink">Storage almost full</p>
            <p class="mt-0.5 text-xs text-muted">7.2 GB of 10 GB used</p>
            <div class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-line">
                <div class="h-full w-[72%] rounded-full bg-accent"></div>
            </div>
        </div>
        <div class="flex items-center gap-3 rounded-xl px-2 py-1.5">
            <span class="flex h-9 w-9 items-center justify-center rounded-full bg-accent-soft text-xs font-semibold text-accent">AK</span>
            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-semibold text-ink">Alex Kim</p>
                <p class="truncate text-xs text-muted">alex@storefront.app</p>
            </div>
            <button type="button" class="rounded-md p-1.5 text-muted hover:bg-canvas hover:text-ink">
                <x-dashboard.icon name="log-out" class="h-4.5 w-4.5" />
            </button>
        </div>
    </div>
</aside>

{{-- Mobile backdrop --}}
<div id="sidebar-backdrop" class="fixed inset-0 z-30 hidden bg-ink/30 backdrop-blur-sm lg:hidden"></div>
