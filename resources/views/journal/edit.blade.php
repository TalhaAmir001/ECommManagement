@extends('layouts.dashboard')

@section('title', 'Edit ' . $entry->reference)

@section('content')
    <div class="mx-auto max-w-5xl px-4 py-6 sm:px-6 lg:px-8">
        <div class="flex flex-col gap-2">
            <a href="{{ route('journal.show', $entry) }}" class="inline-flex items-center gap-1 text-xs font-medium text-muted hover:text-ink">
                <x-dashboard.icon name="chevron-left" class="h-3.5 w-3.5" />
                Back to {{ $entry->reference }}
            </a>
            <div class="flex flex-wrap items-center gap-2">
                <h1 class="text-[22px] font-semibold tracking-tight text-ink">Edit {{ $entry->reference }}</h1>
                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold {{ $entry->status === 'posted' ? 'bg-positive-soft text-positive' : 'bg-canvas text-muted' }}">
                    {{ ucfirst($entry->status) }}
                </span>
            </div>
            <p class="text-sm text-muted">
                Editing replaces the lines on this entry. The debits and credits will always remain balanced.
            </p>
        </div>

        @if ($errors->any())
            <div class="mt-4 rounded-lg border border-negative/30 bg-negative-soft px-4 py-3 text-sm text-negative">
                <ul class="list-disc space-y-0.5 pl-5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('journal.update', $entry) }}" class="mt-6">
            @csrf
            @method('PUT')
            @include('journal._form')
        </form>
    </div>
@endsection
