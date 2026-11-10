{{-- Onboarding checklist: application, vehicle, documents, review, with the go-online answer in the driver's words. --}}
@php
    $profile = $state->profile();
    $vehicles = $state->vehicles();
    $counts = $documents['counts'];
    $docsDone = $counts['total'] > 0 && $counts['done'] === $counts['total'];
    $approved = $profile->status === 'approved';
    $steps = [
        ['Application sent', $approved ? 'Approved' : ucfirst($profile->status === 'pending' ? 'waiting for review' : $profile->status), 'done', null],
        ['Vehicle', $vehicles->isEmpty() ? 'Add the car you will drive' : $vehicles->count().' '.\Illuminate\Support\Str::plural('vehicle', $vehicles->count()).($state->activeVehicle() ? ', '.$state->activeVehicle()->plate_number.' active' : ', none active'), $state->activeVehicle() ? 'done' : 'current', route('drivers.vehicles.index')],
        ['Documents', "{$counts['done']} of {$counts['total']} approved".($counts['waiting'] ? ", {$counts['waiting']} waiting for review" : '').($counts['action'] ? ", {$counts['action']} need you" : ''), $docsDone ? 'done' : 'current', route('drive.documents')],
        ['Review by a person', $approved ? 'Done' : 'Your profile is reviewed by '.($profile->fleet_id ? 'your fleet or Dot.Ehail operations' : 'Dot.Ehail operations'), $approved ? 'done' : 'todo', null],
    ];
@endphp
<x-driver-layout title="Getting ready to drive">
    <x-slot:head>
        @vite(['resources/css/driver.css'])
    </x-slot:head>
    <x-slot:state>
        <section class="eh-statebar" data-state="offline" aria-labelledby="drive-state-title">
            <x-eh.icon name="document-check" class="eh-statebar__mark" />
            <div class="eh-statebar__text">
                <h1 class="eh-statebar__title" id="drive-state-title">{{ $approved ? 'Almost ready' : 'Application in review' }}</h1>
                <p class="eh-statebar__sub">{{ $approved ? 'Finish the list below to go online.' : 'You can drive once your profile and documents are approved.' }}</p>
            </div>
        </section>
    </x-slot:state>

    <div class="dr-page">
        <section class="dr-section" aria-labelledby="steps-h">
            <h2 id="steps-h">Your checklist</h2>
            <ol class="dr-steps">
                @foreach ($steps as [$title, $meta, $stepState, $href])
                    <li data-state="{{ $stepState }}">
                        <span class="dr-steps__dot" aria-hidden="true">@if($stepState === 'done')<x-eh.icon name="check" />@endif</span>
                        <span class="dr-steps__title">{{ $title }}<span class="dr-steps__meta">{{ $meta }}</span></span>
                        @if ($href)<a class="dr-textlink" href="{{ $href }}">Open<span class="eh-sr-only"> {{ strtolower($title) }}</span></a>@else<span></span>@endif
                    </li>
                @endforeach
            </ol>
        </section>

        <section class="dr-panel" aria-labelledby="elig-h">
            <h2 id="elig-h">{{ $eligibility['allowed'] ? 'You can go online' : 'You can go online when:' }}</h2>
            @if ($eligibility['allowed'])
                <x-eh.button size="driver" :href="route('drive.home')" icon="online" block>Open the driver console</x-eh.button>
            @else
                <ul class="dr-checks__list">
                    @foreach (\App\Http\Controllers\Driver\Support\DriverState::reasonLines($eligibility['reasons']) as $line)
                            <li @if($line['waiting']) data-waiting @endif><x-eh.icon :name="$line['waiting'] ? 'clock' : 'alert'" /><span>{{ $line['text'] }}</span></li>
                        @endforeach
                </ul>
                    @if (collect($eligibility['reasons'])->pluck('code')->intersect(['vehicle_out_of_service', 'vehicle_retired', 'fleet_vehicle_unassigned'])->isNotEmpty())
                        <p class="dr-note">Only your fleet owner can put this vehicle back on the road or give you another one.</p>
                    @endif
            @endif
            @unless ($eligibility['documents_enforced'])
                <p class="dr-note">Document checks are switched off by the operator for a transition period; your profile and vehicle are still checked.</p>
            @endunless
        </section>

        @if ($counts['action'] > 0)
            <x-eh.button size="driver" :href="route('drive.documents')" icon="upload" block>Upload documents</x-eh.button>
        @endif
    </div>
</x-driver-layout>
