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
        // [
        //     'label' => 'Commerce',
        //     'items' => [
        //         ['icon' => 'orders', 'label' => 'Orders', 'route' => 'orders.index', 'badge' => $orderCount],
        //     ],
        // ],
        [
            'label' => 'Insights',
            'items' => [
                ['icon' => 'orders', 'label' => 'Orders', 'route' => 'orders.index', 'badge' => $orderCount],
                ['icon' => 'reports', 'label' => 'Reports', 'route' => 'audit.index'],
                ['icon' => 'truck', 'label' => 'Shipments', 'route' => 'shipments.index'],
            ],
        ],
        [
            'label' => 'Finance',
            'items' => [
                ['icon' => 'book-open', 'label' => 'Journal', 'route' => 'journal.index'],
            ],
        ],
        [
            'label' => 'Management',
            'items' => [
                ['icon' => 'settings', 'label' => 'Settings', 'route' => 'couriers.settings'],
            ],
        ],
    ];

    // User footer. Falls back to the placeholder identity used by the
    // removed topbar when no user is authenticated, so the sidebar still
    // renders sensibly before auth is wired up.
    $authUser = auth()->user();
    $userName = $authUser?->name ?? 'Alex Kim';
    $userEmail = $authUser?->email ?? 'alex@storefront.app';
    $userInitials = mb_strtoupper(
        collect(preg_split('/\s+/u', trim($userName)) ?: [])
            ->filter()
            ->map(fn ($part) => mb_substr($part, 0, 1))
            ->take(2)
            ->implode('')
    );
    if ($userInitials === '') {
        $userInitials = 'U';
    }
@endphp

<aside id="dashboard-sidebar"
    class="fixed inset-y-0 left-0 z-40 flex w-64 -translate-x-full flex-col border-r border-line bg-surface transition-transform duration-200 ease-out lg:static lg:shrink-0 lg:translate-x-0">
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
                        @php
                            $isActive = false;
                            if ($item['route'] !== null) {
                                $isActive = $currentRoute === $item['route']
                                    || (str_starts_with($item['route'] . '.', $currentRoute . '.'));
                            }
                        @endphp
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

    {{-- User footer (curated.design-style: avatar + name + email + chevron).
         Click reveals a small dropdown anchored above the trigger. The
         footer is the only place on the page that knows about the
         authenticated operator. --}}
    @php $userMenuId = 'sidebar-user-menu'; @endphp
    <div class="relative shrink-0 border-t border-line bg-surface p-3">
        <button id="sidebar-user-menu-trigger" type="button"
            data-user-menu-trigger
            aria-controls="{{ $userMenuId }}"
            aria-expanded="false"
            aria-haspopup="menu"
            class="group flex w-full items-center gap-3 rounded-lg p-2 text-left transition-colors duration-200 hover:bg-canvas focus:outline-none focus-visible:ring-2 focus-visible:ring-ink/10">
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-accent-soft text-xs font-semibold text-accent">
                {{ $userInitials }}
            </span>
            <span class="min-w-0 flex-1">
                <span class="block truncate text-sm font-medium text-ink">{{ $userName }}</span>
                <span class="block truncate text-xs text-muted">{{ $userEmail }}</span>
            </span>
            <x-dashboard.icon name="chevron-down"
                class="h-4 w-4 shrink-0 text-faint transition-transform duration-200 group-aria-expanded:rotate-180 group-hover:text-muted" />
        </button>

        {{-- Dropdown panel. Opens above the trigger (bottom-full) so it
             never collides with the viewport edge on short sidebars. --}}
        <div id="{{ $userMenuId }}"
            data-user-menu
            role="menu"
            aria-labelledby="sidebar-user-menu-trigger"
            class="absolute bottom-full left-3 right-3 z-50 mb-2 hidden rounded-lg border border-line bg-surface p-1 shadow-lg shadow-ink/5">

            <div class="border-b border-line px-3 py-2.5">
                <p class="text-[11px] font-medium uppercase tracking-[0.12em] text-faint">Signed in as</p>
                <p class="mt-1 truncate text-sm font-medium text-ink">{{ $userName }}</p>
                <p class="truncate text-xs text-muted">{{ $userEmail }}</p>
            </div>

            <div class="p-1" role="none">
                <a href="{{ route('couriers.settings') }}"
                    role="menuitem"
                    class="group flex items-center gap-2.5 rounded-md px-2.5 py-2 text-sm text-muted transition-colors hover:bg-canvas hover:text-ink">
                    <x-dashboard.icon name="settings" class="h-[18px] w-[18px] text-faint group-hover:text-ink" />
                    <span>Settings</span>
                </a>

                <div class="my-1 h-px bg-line" role="separator"></div>

                {{-- Sign out targets the standard Laravel logout endpoint
                     once the auth scaffold is added. Until then the form
                     will 404 — wired now so the only thing left to do is
                     add the route. --}}
                <form method="POST" action="{{ url('/logout') }}" role="none">
                    @csrf
                    <button type="submit"
                        role="menuitem"
                        class="group flex w-full items-center gap-2.5 rounded-md px-2.5 py-2 text-left text-sm text-muted transition-colors hover:bg-negative-soft hover:text-negative">
                        <x-dashboard.icon name="log-out" class="h-[18px] w-[18px] text-faint group-hover:text-negative" />
                        <span>Sign out</span>
                    </button>
                </form>
            </div>
        </div>
    </div>

</aside>

{{-- Mobile backdrop --}}
<div id="sidebar-backdrop" class="fixed inset-0 z-30 hidden bg-ink/30 backdrop-blur-sm lg:hidden"></div>
