{{-- One support case and its messages, as the support domain shows them to the driver. --}}
<x-driver-layout title="Support case">
    <x-slot:head>
        @vite(['resources/css/driver.css', 'resources/js/driver/pages.js'])
    </x-slot:head>
    <x-slot:header>
        <div class="dr-head">
            <h1>{{ $case['subject'] }}</h1>
            <p>{{ $case['reference'] }}, {{ $case['category_label'] }}. Status: {{ str_replace('_', ' ', $case['status']) }}.@if($case['resolution']) {{ $case['resolution'] }}.@endif</p>
        </div>
    </x-slot:header>
    <div class="dr-page">
        <ol class="dr-docs" aria-label="Messages">
            @foreach ($messages as $m)
                <li class="dr-doc">
                    <p class="dr-note"><strong>{{ $m['from'] === 'you' ? 'You' : $m['from'] }}</strong>, {{ \Illuminate\Support\Carbon::parse($m['at'])->isoFormat('D MMM HH:mm') }}</p>
                    <p style="margin:0;white-space:pre-line">{{ $m['body'] }}</p>
                    @if ($m['attachment'])<a class="dr-textlink" href="{{ $m['attachment']['url'] }}">{{ $m['attachment']['name'] }}</a>@endif
                </li>
            @endforeach
        </ol>
        @unless (in_array($case['status'], ['closed'], true))
            <form class="dr-form" method="POST" action="{{ route('support.cases.reply', $case['id']) }}" data-dr-json data-reload="1" data-done="Sent." data-dr-typing>
                @csrf
                <x-eh.field label="Reply" name="body" type="textarea" maxlength="5000" required />
                <x-eh.button type="submit" size="driver">Send reply</x-eh.button>
            </form>
            <p class="dr-hold dr-alert dr-alert--caution">Messages are paused while you drive.</p>
        @endunless
        <x-eh.button variant="ghost" size="driver" :href="route('drive.safety')" icon="arrow-left">Help and safety</x-eh.button>
    </div>
</x-driver-layout>
