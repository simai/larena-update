@extends('larena-admin::layouts.app')

@section('title', $heading)
@section('heading', $heading)

@section('content')
    @php($C = \Larena\Admin\Runtime\AdminControls::class)
    <section class="larena-panel larena-panel-padded" data-integrity-plan aria-live="polite">
        @foreach ($blockers as $blocker)
            {!! $C::alert(\Illuminate\Support\Str::ucfirst($blocker['text']), $blocker['level']) !!}
        @endforeach
        @if ($planned !== null)
            {{ $planned }}
        @elseif ($empty && $blockers === [])
            <sf-empty-state kind="no-data" size="1/2" heading-level="2" icon="task_alt" heading="{{ __('larena-update-client::integrity.restore.nothing') }}" text="{{ __('larena-update-client::integrity.restore.nothing_text') }}"></sf-empty-state>
        @endif
        <div class="larena-page-actions m-top-1">
            <sf-button text="{{ __('larena-update-client::integrity.finding.back') }}" appearance="outline" scheme="on-surface" href="{{ $back }}"></sf-button>
        </div>
    </section>
@endsection
