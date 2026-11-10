@php
    $channels = $state['channels'];
    $channelRows = [
        'in_app' => ['In the app', 'inbox', 'On', 'go'],
        'push' => ['Push notifications', 'bell', $channels['push']['available'] ? 'Available' : 'Not set up', $channels['push']['available'] ? 'go' : 'neutral'],
        'email' => ['Email', 'messages', match ($channels['email']['state']) { 'not_delivering' => 'Not delivered', 'notify_only' => 'Partly', default => 'On' }, $channels['email']['available'] ? ($channels['email']['state'] === 'notify_only' ? 'caution' : 'go') : 'caution'],
        'sms' => ['SMS', 'phone', $channels['sms']['available'] ? 'Available' : 'Not available', $channels['sms']['available'] ? 'go' : 'neutral'],
    ];
    $channelNames = ['push' => 'Push', 'email' => 'Email', 'sms' => 'SMS'];
    $tz = config('ehail_pricing.default_timezone', 'Africa/Johannesburg');
@endphp
<x-comms::shell :surface="$surface" title="Notification settings">
    <x-slot:header>
        <div class="eh-pagehead__text">
            <h1 class="eh-h1">Notification settings</h1>
            <p class="eh-small" style="margin:0">Everything we send is kept in your inbox. Choose what else reaches you, and how.</p>
        </div>
        <div class="comms-headactions">
            <x-eh.button :href="route($routePrefix.'.notifications')" variant="ghost" size="sm" icon="arrow-left">Inbox</x-eh.button>
        </div>
    </x-slot:header>

    <div class="comms-settings">
        <section class="comms-block" aria-labelledby="comms-channels-title">
            <div class="comms-block__label">
                <h2 class="eh-h3" id="comms-channels-title">How we can reach you</h2>
                <p class="eh-small">What this service can deliver right now. We never show a message as sent unless the channel took it.</p>
            </div>
            <ul class="comms-channels">
                @foreach ($channelRows as $key => [$name, $icon, $word, $tone])
                    <li class="comms-channel" data-channel="{{ $key }}">
                        <x-eh.icon :name="$icon" class="comms-channel__icon" />
                        <div class="comms-channel__main">
                            <p class="comms-channel__name">{{ $name }} <x-eh.badge :tone="$tone">{{ $word }}</x-eh.badge></p>
                            <p class="comms-channel__text">{{ $channels[$key]['message'] }}</p>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>

        <section class="comms-block" aria-labelledby="comms-device-title" data-comms-push
                 data-configured="{{ $state['push']['configured'] ? 'true' : 'false' }}"
                 data-public-key="{{ $state['push']['public_key'] }}"
                 data-subscribe-url="{{ route('ehail.comms.push.subscribe') }}"
                 data-status-url="{{ route('ehail.comms.push.status') }}"
                 data-test-url="{{ route('ehail.comms.push.test') }}">
            <div class="comms-block__label">
                <h2 class="eh-h3" id="comms-device-title">Push on this device</h2>
                <p class="eh-small">Push is switched on per phone or browser. Turn it on here on each one you use.</p>
            </div>
            <div class="comms-panel">
                <p class="comms-device__state" data-push-state role="status">
                    @if (! $state['push']['configured'])
                        Push notifications are not set up on this service yet, so this device cannot be switched on.
                    @else
                        Checking this browser.
                    @endif
                </p>
                <noscript><p class="comms-device__state">Turning push on needs JavaScript in this browser.</p></noscript>
                @if ($state['push']['configured'])
                    <div class="comms-device__actions">
                        <x-eh.button data-push-on hidden icon="bell">Turn on for this device</x-eh.button>
                        <x-eh.button data-push-off hidden variant="secondary" icon="bell-off">Turn off for this device</x-eh.button>
                        <x-eh.button data-push-test hidden variant="ghost" icon="check">Send a test</x-eh.button>
                    </div>
                @endif
                @if (count($state['push']['devices']))
                    <div class="comms-devices">
                        <h3 class="comms-devices__title">Devices with push on</h3>
                        <ul>
                            @foreach ($state['push']['devices'] as $device)
                                <li>{{ $device['label'] }}<span class="eh-small">, on since {{ \Illuminate\Support\Carbon::parse($device['since'])->setTimezone($tz)->format('j M Y') }}</span></li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        </section>

        <form method="POST" action="{{ route($routePrefix.'.notifications.settings.save') }}" class="comms-block comms-block--form" aria-labelledby="comms-what-title">
            @csrf
            @method('PUT')
            <div class="comms-block__label">
                <h2 class="eh-h3" id="comms-what-title">What we send</h2>
                <p class="eh-small">Each kind of message, and where it goes besides your inbox.</p>
            </div>
            <div class="comms-panel comms-panel--flush">
                <ul class="comms-cats">
                    @foreach ($state['categories'] as $key => $category)
                        <li class="comms-cat {{ $category['critical'] ? 'is-critical' : '' }}">
                            <div class="comms-cat__text">
                                <h3 class="comms-cat__title">
                                    {{ $category['label'] }}
                                    @if ($category['critical'])<x-eh.badge tone="stop" icon="lock">Always on</x-eh.badge>@endif
                                </h3>
                                <p class="comms-cat__desc">{{ $category['description'] }}</p>
                                @if ($category['critical'])<p class="comms-cat__why">{{ $category['critical_reason'] }}</p>@endif
                                @isset($recent[$key])
                                    <p class="comms-cat__last">Last: {{ $recent[$key]['title'] }}, {{ \Illuminate\Support\Carbon::parse($recent[$key]['at'])->setTimezone($tz)->diffForHumans() }}</p>
                                @endisset
                            </div>
                            <div class="comms-cat__switches">
                                @if (count($category['channels']) === 0)
                                    <p class="comms-cat__inapp">In the app only</p>
                                @endif
                                @if ($category['critical'])
                                    <ul class="comms-locked" aria-label="{{ $category['label'] }} channels">
                                        @foreach ($category['channels'] as $channel => $choice)
                                            @php($smsLine = ! $state['sms']['configured'] ? 'not available on this service' : ($state['sms']['verified'] ? 'on, to your confirmed number' : 'on once you confirm a number'))
                                            <li class="{{ $channel === 'sms' && ! $state['sms']['verified'] ? 'is-off' : '' }}">
                                                <x-eh.icon :name="$channel === 'sms' && ! $state['sms']['verified'] ? 'minus' : 'check'" />
                                                <span><strong>{{ $channelNames[$channel] }}</strong> {{ $channel === 'sms' ? $smsLine : 'always on' }}</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                @else
                                    @foreach ($category['channels'] as $channel => $choice)
                                        <x-eh.switch :name="'prefs['.$key.']['.$channel.']'" :label="$channelNames[$channel]" :checked="$choice['enabled']"
                                            :description="$choice['enabled'] ? 'On' : 'Off'" data-comms-switch />
                                    @endforeach
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
                <div class="comms-save">
                    <x-eh.button type="submit" icon="check">Save choices</x-eh.button>
                </div>
            </div>
        </form>

        @if ($state['sms']['configured'])
            <section class="comms-block" aria-labelledby="comms-sms-title" data-comms-sms
                     data-start-url="{{ route('ehail.comms.sms.start') }}" data-confirm-url="{{ route('ehail.comms.sms.confirm') }}" data-remove-url="{{ route('ehail.comms.sms.destroy') }}">
                <div class="comms-block__label">
                    <h2 class="eh-h3" id="comms-sms-title">SMS for safety</h2>
                    <p class="eh-small">Used only for safety messages. We send a code to confirm the number is yours.</p>
                </div>
                <div class="comms-panel">
                    @if ($state['sms']['verified'])
                        <p class="comms-device__state">Safety SMS go to {{ $state['sms']['number'] }}.</p>
                        <x-eh.button variant="secondary" data-sms-remove icon="trash">Remove this number</x-eh.button>
                    @else
                        <div class="comms-sms" data-sms-step="number" @if($state['sms']['awaiting_code']) hidden @endif>
                            <x-eh.field label="Mobile number" name="sms_number" type="tel" autocomplete="tel" hint="For example 082 123 4567" />
                            <x-eh.button data-sms-start icon="phone">Send a code</x-eh.button>
                        </div>
                        <div class="comms-sms" data-sms-step="code" @unless($state['sms']['awaiting_code']) hidden @endunless>
                            <x-eh.field label="Code from the SMS" name="sms_code" inputmode="numeric" autocomplete="one-time-code" :hint="$state['sms']['number'] ? 'Sent to '.$state['sms']['number'] : null" />
                            <x-eh.button data-sms-confirm icon="check">Confirm number</x-eh.button>
                        </div>
                        <noscript><p class="comms-device__state">Confirming a number needs JavaScript in this browser.</p></noscript>
                    @endif
                    <p class="comms-sms__message eh-small" data-sms-message role="status"></p>
                </div>
            </section>
        @endif
    </div>
</x-comms::shell>
