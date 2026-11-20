@extends('larena-admin::layouts.app')

@section('title', __('larena-update-client::integrity.title'))
@section('heading', __('larena-update-client::integrity.title'))
@section('description', __('larena-update-client::integrity.description'))

@section('content')
    @php($C = \Larena\Admin\Runtime\AdminControls::class)
    <div data-integrity-page data-integrity-core="{{ $check->status }}">
        @if ($notice)
            {!! $C::alert($notice, 'info') !!}
        @endif

        {{-- A2: the main files, checked on this very request. --}}
        <section class="larena-panel larena-panel-padded" aria-labelledby="integrity-core-heading" data-integrity-part="core">
            <h2 id="integrity-core-heading">{{ __('larena-update-client::integrity.core.heading') }}</h2>
            @if ($check->status === 'changed')
                {!! $C::alert(__('larena-update-client::integrity.core.changed_text', ['count' => count($check->problems)]), 'danger', $checkWords['status']) !!}
                <ul class="m-top-1">
                    @foreach ($check->problems as $path => $problem)
                        <li><code>{{ $path }}</code> — {{ __('larena-update-client::integrity.problems.' . $problem) }}</li>
                    @endforeach
                </ul>
            @elseif ($check->status === 'ok')
                {!! $C::alert(__('larena-update-client::integrity.core.ok_text', ['count' => $check->files]), 'success', $checkWords['status']) !!}
            @else
                {!! $C::alert(\Illuminate\Support\Str::ucfirst((string) $checkWords['reason']), 'warning', $checkWords['status']) !!}
            @endif
        </section>

        {{-- A3: the last full check, honest about what it could not see. --}}
        <section class="larena-panel larena-panel-padded" aria-labelledby="integrity-full-heading" data-integrity-part="full">
            <h2 id="integrity-full-heading">{{ __('larena-update-client::integrity.full.heading') }}</h2>
            @if ($running)
                <sf-progress-bar indeterminate label="{{ __('larena-update-client::integrity.full.running', ['count' => (int) ($running['counts']['checked'] ?? 0)]) }}"></sf-progress-bar>
                <p>{{ __('larena-update-client::integrity.full.running_text') }}</p>
            @endif
            @if ($last)
                <dl class="larena-recipe-facts" data-integrity-result="{{ $last['result'] }}">
                    <div><dt>{{ __('larena-update-client::integrity.full.result') }}</dt><dd>{{ $result($last['result']) }}@if ($last['reason']) — {{ $reason($last['reason']) }}@endif</dd></div>
                    <div><dt>{{ __('larena-update-client::integrity.full.finished') }}</dt><dd><sf-time datetime="{{ $last['finished_at'] }}" format="datetime"></sf-time></dd></div>
                    <div><dt>{{ __('larena-update-client::integrity.full.files') }}</dt><dd>{{ (int) ($last['counts']['checked'] ?? 0) }}</dd></div>
                    <div><dt>{{ __('larena-update-client::integrity.full.folders') }}</dt><dd>{{ (int) ($last['counts']['folders'] ?? 0) }}</dd></div>
                    @if ((int) ($last['counts']['errors'] ?? 0) > 0)
                        <div><dt>{{ __('larena-update-client::integrity.full.errors') }}</dt><dd>{{ (int) $last['counts']['errors'] }}</dd></div>
                    @endif
                </dl>
            @elseif (! $running)
                <sf-empty-state kind="no-data" size="1/2" heading-level="3" icon="fact_check" heading="{{ __('larena-update-client::integrity.full.never') }}" text="{{ __('larena-update-client::integrity.full.never_text') }}"></sf-empty-state>
            @endif
            @if ($canAct)
                <div class="larena-page-actions m-top-1">
                    <form method="post" action="{{ route('larena.update-client.integrity.check') }}">
                        @csrf
                        {!! $C::submit(__('larena-update-client::integrity.full.check_now'), 'secondary') !!}
                    </form>
                    @if ($open !== [] || $check->status === 'changed')
                        <sf-button text="{{ __('larena-update-client::integrity.restore.button') }}" appearance="filled" scheme="primary" href="{{ route('larena.update-client.integrity.restore', [], false) }}" data-integrity-restore></sf-button>
                    @endif
                </div>
            @endif
        </section>

        {{-- The findings: open first, then accepted, then the recently resolved. --}}
        @foreach (['open' => $open, 'accepted' => $accepted, 'resolved' => $resolved] as $group => $items)
            @if ($items !== [] || $group === 'open')
                <section class="larena-panel larena-panel-padded" aria-labelledby="integrity-{{ $group }}-heading" data-integrity-findings="{{ $group }}">
                    <h2 id="integrity-{{ $group }}-heading">{{ __('larena-update-client::integrity.findings.' . $group, ['count' => count($items)]) }}</h2>
                    @if ($items === [])
                        <p>{{ __('larena-update-client::integrity.findings.none') }}</p>
                    @else
                        <ul class="m-0">
                            @foreach ($items as $finding)
                                <li class="m-bottom-1">
                                    <a href="{{ route('larena.update-client.integrity.finding', ['key' => $finding['key']], false) }}"><code>{{ $label($finding) }}</code></a>
                                    — {{ $kind($finding['kind']) }}
                                    <span class="text-small color-on-surface-variant">· <sf-time datetime="{{ $finding['last_seen'] ?? '' }}" format="datetime"></sf-time></span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            @endif
        @endforeach

        {{-- The quarantine: extra files moved away, never deleted; each can come back. --}}
        <section class="larena-panel larena-panel-padded" aria-labelledby="integrity-quarantine-heading" data-integrity-part="quarantine">
            <h2 id="integrity-quarantine-heading">{{ __('larena-update-client::integrity.quarantine.title', ['count' => count($quarantine)]) }}</h2>
            <p class="text-small color-on-surface-variant">{{ __('larena-update-client::integrity.quarantine.where') }}</p>
            @if ($quarantine === [])
                <p>{{ __('larena-update-client::integrity.quarantine.empty') }}</p>
            @else
                <ul class="m-0">
                    @foreach ($quarantine as $item)
                        <li class="m-bottom-1">
                            <code>{{ $label($item) }}</code>
                            <span class="text-small color-on-surface-variant">· <sf-time datetime="{{ $item['moved_at'] }}" format="datetime"></sf-time></span>
                            @if ($canAct)
                                · <a href="{{ route('larena.update-client.integrity.quarantine', ['id' => $item['id']], false) }}">{{ __('larena-update-client::integrity.quarantine.return') }}</a>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
            <p class="text-small color-on-surface-variant">{{ __('larena-update-client::integrity.archive_where', ['path' => $archive]) }}</p>
        </section>
    </div>
@endsection
