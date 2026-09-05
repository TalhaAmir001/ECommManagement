@extends('layouts.dashboard')

@section('title', 'Edit Vendor')

@section('content')
    <div class="mx-auto max-w-5xl px-4 py-6 sm:px-6 lg:px-8">
        <div class="flex flex-col gap-2">
            <a href="{{ route('vendors.show', $vendor) }}" class="inline-flex items-center gap-1 text-xs font-medium text-muted hover:text-ink">
                <x-dashboard.icon name="chevron-left" class="h-3.5 w-3.5" />
                Back to {{ $vendor->name }}
            </a>
            <h1 class="text-[22px] font-semibold tracking-tight text-ink">Edit vendor</h1>
            <p class="text-sm text-muted">Update the supplier details for {{ $vendor->name }}.</p>
        </div>

        @if ($errors->any())
            <div class="mt-4 rounded-lg border border-negative/30 bg-negative-soft px-4 py-3 text-sm text-negative">
                <p class="font-semibold">Please fix the following:</p>
                <ul class="mt-1 list-disc space-y-0.5 pl-5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('vendors.update', $vendor) }}" class="mt-6">
            @csrf
            @method('PUT')
            @include('vendors._form', ['vendor' => $vendor])
        </form>
    </div>
@endsection
