@extends('larena-admin::layouts.app')

@section('title', __('larena-update-client::integrity.finding.title'))
@section('heading', $label)
@section('eyebrow', __('larena-update-client::integrity.finding.title'))
@section('description', $explain)

@section('content')
    @php($C = \Larena\Admin\Runtime\AdminControls::class)
    <div data-integrity-finding="{{ $finding['key'] }}" data-integrity-state="{{ $finding['state'] }}">
        @if ($notice)
            {!! $C::alert($notice, 'info') !!}
        @endif
        <section class="larena-panel larena-panel-padded" aria-labelledby="finding-heading">
            <h2 id="finding-heading">{{ $kind }}</h2>
            @if ($finding['state'] === 'open')
                {!! $C::alert($explain, 'danger') !!}
            @endif
            <dl class="larena-recipe-facts">
                <div><dt>{{ __('larena-update-client::integrity.finding.state') }}</dt><dd>{{ $state }}</dd></div>
                <div><dt>{{ __('larena-update-client::integrity.finding.path') }}</dt><dd><code>{{ $label }}</code></dd></div>
                <div><dt>{{ __('larena-update-client::integrity.finding.first_seen') }}</dt><dd><sf-time datetime="{{ $finding['first_seen'] ?? '' }}" format="datetime"></sf-time></dd></div>
                <div><dt>{{ __('larena-update-client::integrity.finding.last_seen') }}</dt><dd><sf-time datetime="{{ $finding['last_seen'] ?? '' }}" format="datetime"></sf-time></dd></div>
                <div><dt>{{ __('larena-update-client::integrity.finding.count') }}</dt><dd>{{ (int) ($finding['occurrence_count'] ?? 1) }}</dd></div>
                @if (! empty($finding['exception']))
                    <div><dt>{{ __('larena-update-client::integrity.finding.exception') }}</dt><dd>{{ __('larena-update-client::integrity.finding.exception_value', ['scope' => $finding['exception']['scope'], 'reason' => $finding['exception']['reason']]) }} <sf-time datetime="{{ $finding['exception']['until'] }}" format="date"></sf-time></dd></div>
                @endif
                <div><dt>{{ __('larena-update-client::integrity.finding.what_restore_does') }}</dt><dd>{{ $action }}</dd></div>
            </dl>
            <details class="m-top-1">
                <summary class="color-primary">{{ __('larena-update-client::integrity.finding.more') }}</summary>
                <dl class="larena-recipe-facts">
                    <div><dt>{{ __('larena-update-client::integrity.finding.key') }}</dt><dd><code>{{ $finding['key'] }}</code></dd></div>
                    <div><dt>{{ __('larena-update-client::integrity.finding.expected') }}</dt><dd><code>{{ $finding['expected_sha256'] ?? '—' }}</code></dd></div>
                    <div><dt>{{ __('larena-update-client::integrity.finding.actual') }}</dt><dd><code>{{ $finding['actual_sha256'] ?? '—' }}</code></dd></div>
                    <div><dt>{{ __('larena-update-client::integrity.finding.bytes') }}</dt><dd>{{ $finding['bytes'] ?? '—' }}</dd></div>
                </dl>
            </details>
            <div class="larena-page-actions m-top-1">
                @if ($canRestore)
                    <sf-button text="{{ __('larena-update-client::integrity.restore.button') }}" appearance="filled" scheme="primary" href="{{ route('larena.update-client.integrity.restore', ['finding' => $finding['key']], false) }}" data-integrity-restore></sf-button>
                @endif
                <sf-button text="{{ __('larena-update-client::integrity.finding.back') }}" appearance="outline" scheme="on-surface" href="{{ route('larena.update-client.integrity.index', [], false) }}"></sf-button>
            </div>
        </section>

        @if ($canAccept)
            {{-- «Принять изменение»: an exception with a scope and an end date, never for good. --}}
            <section class="larena-panel larena-panel-padded" aria-labelledby="finding-accept-heading" data-integrity-accept>
                <h2 id="finding-accept-heading">{{ __('larena-update-client::integrity.accept.heading') }}</h2>
                <p>{{ __('larena-update-client::integrity.accept.text') }}</p>
                <form method="post" action="{{ route('larena.update-client.integrity.exception', ['key' => $finding['key']], false) }}">
                    @csrf
                    {!! $C::select('integrity-scope', 'scope', __('larena-update-client::integrity.accept.scope'), ['file' => __('larena-update-client::integrity.accept.scope_file'), 'folder' => __('larena-update-client::integrity.accept.scope_folder')], 'file') !!}
                    {!! $C::select('integrity-days', 'days', __('larena-update-client::integrity.accept.until'), ['7' => __('larena-update-client::integrity.accept.days', ['count' => 7]), '30' => __('larena-update-client::integrity.accept.days', ['count' => 30]), '90' => __('larena-update-client::integrity.accept.days', ['count' => 90])], '30') !!}
                    {!! $C::input('integrity-reason', 'reason', __('larena-update-client::integrity.accept.reason'), '', true, 500) !!}
                    {!! $C::submit(__('larena-update-client::integrity.accept.button'), 'secondary') !!}
                </form>
            </section>
        @endif
    </div>
@endsection
