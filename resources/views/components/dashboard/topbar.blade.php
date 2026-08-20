<header class="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-line bg-canvas/85 px-4 backdrop-blur-md sm:px-6">
    {{-- Mobile menu trigger --}}
    <button id="sidebar-open" type="button"
        class="rounded-md p-2 text-muted hover:bg-surface hover:text-ink lg:hidden">
        <x-dashboard.icon name="menu" class="h-5 w-5" />
    </button>

    {{-- Search --}}
    <div class="relative flex-1 sm:max-w-md">
        <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-faint">
            <x-dashboard.icon name="search" class="h-4 w-4" />
        </span>
        <input type="search" placeholder="Search orders, products, customers…"
            class="w-full rounded-lg border border-line bg-surface py-2 pl-9 pr-3 text-sm text-ink placeholder:text-faint focus:border-ink focus:outline-none focus:ring-2 focus:ring-ink/10" />
    </div>

    <div class="ml-auto flex items-center gap-1 sm:gap-2">
        <button type="button"
            class="relative rounded-lg p-2 text-muted hover:bg-surface hover:text-ink">
            <x-dashboard.icon name="bell" class="h-5 w-5" />
            <span class="absolute right-1.5 top-1.5 h-2 w-2 rounded-full bg-accent ring-2 ring-canvas"></span>
        </button>

        <div class="mx-1 hidden h-6 w-px bg-line sm:block"></div>

        <button type="button" class="flex items-center gap-2.5 rounded-lg py-1.5 pl-1.5 pr-2 hover:bg-surface">
            <span class="flex h-8 w-8 items-center justify-center rounded-full bg-accent-soft text-xs font-semibold text-accent">AK</span>
            <span class="hidden text-left sm:block">
                <span class="block text-sm font-semibold leading-tight text-ink">Alex Kim</span>
                <span class="block text-xs leading-tight text-muted">Admin</span>
            </span>
            <x-dashboard.icon name="chevron-down" class="h-4 w-4 text-faint" />
        </button>
    </div>
</header>
