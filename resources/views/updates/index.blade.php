@extends('larena-admin::layouts.app')

@section('title', __('larena-update-client::updates.title'))
@section('heading', __('larena-update-client::updates.title'))
@section('description', __('larena-update-client::updates.description'))

@section('content')
    @php($C = \Larena\Admin\Runtime\AdminControls::class)
    @php($check = $view['check'])
    <div data-updates-page data-updates-state="{{ $check['status'] ?? 'unchecked' }}">
        @if ($notice)
            {!! $C::alert($notice, 'info') !!}
        @endif

        {{-- What the site runs now. --}}
        <section class="larena-panel larena-panel-padded" aria-labelledby="updates-current-heading" data-updates-part="current">
            <h2 id="updates-current-heading">{{ __('larena-update-client::updates.current.heading') }}</h2>
            <dl class="larena-recipe-facts">
                <div><dt>{{ __('larena-update-client::updates.current.version') }}</dt><dd data-updates-installed>{{ $view['installed'] ?? __('larena-update-client::updates.current.unknown') }}</dd></div>
                @if ($view['channel'])
                    <div><dt>{{ __('larena-update-client::updates.current.channel') }}</dt><dd>{{ $view['channel'] }}</dd></div>
                @endif
                <div><dt>{{ __('larena-update-client::updates.current.sources') }}</dt><dd>{{ $view['sources'] > 0 ? $view['sources'] : __('larena-update-client::updates.current.no_sources') }}</dd></div>
            </dl>
            @if ($view['installed_reason'])
                {!! $C::alert($reason($view['installed_reason']), 'warning') !!}
            @endif
        </section>

        {{-- Whether a newer one exists and what it holds; the check itself is a button, the page never waits for the network. --}}
        <section class="larena-panel larena-panel-padded" aria-labelledby="updates-new-heading" data-updates-part="new">
            <h2 id="updates-new-heading">{{ __('larena-update-client::updates.new.heading') }}</h2>
            @if ($check === null)
                <sf-empty-state kind="no-data" size="1/2" heading-level="3" icon="system_update" heading="{{ __('larena-update-client::updates.new.never') }}" text="{{ __('larena-update-client::updates.new.never_text') }}"></sf-empty-state>
            @elseif ($check['status'] === 'up_to_date')
                {!! $C::alert(__('larena-update-client::updates.new.up_to_date', ['version' => $view['installed']]), 'success') !!}
            @elseif ($check['status'] === 'available')
                {!! $C::alert(__('larena-update-client::updates.new.available', ['version' => $check['version'], 'installed' => $view['installed']]), 'info') !!}
                @if ($notes !== '')
                    <h3>{{ __('larena-update-client::updates.new.notes') }}</h3>
                    <p class="text-break-word" data-updates-notes>{{ $notes }}</p>
                @endif
                @if ($check['backward_incompatible_migrations'] ?? false)
                    {!! $C::alert(__('larena-update-client::updates.new.incompatible'), 'warning') !!}
                @endif
                <p>{{ __('larena-update-client::updates.new.how') }}</p>
            @else
                {!! $C::alert($reason($check['reason'] ?? null), 'warning', __('larena-update-client::updates.new.failed')) !!}
            @endif
            @if ($check !== null)
                <p class="text-small color-on-surface-variant">{{ __('larena-update-client::updates.new.checked') }} <sf-time datetime="{{ $check['checked_at'] }}" format="datetime"></sf-time></p>
            @endif
            @if ($view['queued'])
                {!! $C::alert(__('larena-update-client::updates.new.queued', ['version' => $view['queued']['version']]), 'info') !!}
            @endif
            @if ($canAct)
                <div class="larena-page-actions m-top-1">
                    <form method="post" action="{{ route('larena.update-client.updates.check', [], false) }}" data-larena-tool-form>
                        @csrf
                        {!! $C::submit(__('larena-update-client::updates.new.check_now'), 'secondary') !!}
                    </form>
                    @if (($check['status'] ?? null) === 'available' && ! $view['queued'] && ! $view['unfinished'])
                        <form method="post" action="{{ route('larena.update-client.updates.install', [], false) }}" data-larena-tool-form data-updates-install>
                            @csrf
                            <input type="hidden" name="version" value="{{ $check['version'] }}">
                            {!! $C::submit(__('larena-update-client::updates.new.install', ['version' => $check['version']]), 'primary') !!}
                        </form>
                    @endif
                </div>
                @if (($check['status'] ?? null) === 'available' && ! $view['queue'])
                    <p class="text-small color-on-surface-variant">{{ __('larena-update-client::updates.new.no_queue', ['version' => $check['version']]) }}</p>
                @endif
            @endif
        </section>

        {{-- The installation journal: the last cycles, newest first. --}}
        <section class="larena-panel larena-panel-padded" aria-labelledby="updates-journal-heading" data-updates-part="journal">
            <h2 id="updates-journal-heading">{{ __('larena-update-client::updates.journal.heading') }}</h2>
            @if ($view['unfinished'])
                {!! $C::alert(__('larena-update-client::updates.journal.unfinished'), 'warning') !!}
            @endif
            @if ($view['store_reason'])
                {!! $C::alert($reason($view['store_reason']), 'warning') !!}
            @elseif ($view['cycles'] === [])
                <p>{{ __('larena-update-client::updates.journal.none') }}</p>
            @else
                <ul class="m-0" data-updates-journal>
                    @foreach ($view['cycles'] as $cycle)
                        <li class="m-bottom-1 text-break-word" data-updates-cycle="{{ $cycle['state'] }}">
                            <strong>{{ ($cycle['from'] ?? '?') . ' → ' . ($cycle['to'] ?? '?') }}</strong>
                            — {{ $state($cycle['state']) }}
                            @if (isset($cycle['failed_step']))
                                · {{ __('larena-update-client::updates.journal.step', ['step' => $cycle['failed_step']]) }}: {{ $reason($cycle['reason'] ?? null) }}
                            @endif
                            @if (isset($cycle['updated_at']))
                                <span class="text-small color-on-surface-variant">· <sf-time datetime="{{ $cycle['updated_at'] }}" format="datetime"></sf-time></span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
            <p class="text-small color-on-surface-variant">{{ __('larena-update-client::updates.journal.rollback') }}</p>
            <p class="text-small color-on-surface-variant">{{ __('larena-update-client::updates.journal.recover') }}</p>
        </section>
    </div>
@endsection
