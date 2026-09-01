@extends('layouts.dashboard')

@section('title', 'New Journal Entry')

@section('content')
    <div class="mx-auto max-w-5xl px-4 py-6 sm:px-6 lg:px-8">
        <div class="flex flex-col gap-2">
            <a href="{{ route('journal.index') }}" class="inline-flex items-center gap-1 text-xs font-medium text-muted hover:text-ink">
                <x-dashboard.icon name="chevron-left" class="h-3.5 w-3.5" />
                Back to journal entries
            </a>
            <h1 class="text-[22px] font-semibold tracking-tight text-ink">New journal entry</h1>
            <p class="text-sm text-muted">
                Record an expense or income adjustment that will cut from or add to the profit calculation.
            </p>
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

        <form method="POST" action="{{ route('journal.store') }}" class="mt-6">
            @csrf
            @include('journal._form')
        </form>
    </div>
@endsection
