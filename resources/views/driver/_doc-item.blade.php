{{-- One required document with its status chip and, when it needs the driver, the upload form. --}}
@php
    $needsAction = ! $item['satisfied'] && $item['state'] !== 'pending_review';
    $accept = collect($item['kinds'])->map(fn ($k) => $k === 'pdf' ? 'application/pdf' : 'image/jpeg,image/png,image/webp,image/heic')->join(',');
    $imageOnly = $item['kinds'] === ['image'];
    $formId = 'doc-'.$item['type'].($vehicle ? '-v'.$vehicle->id : '');
    $verb = match ($item['state']) { 'rejected', 'resubmission_requested' => 'Send again', 'missing' => 'Upload', default => 'Replace' };
@endphp
<li class="dr-doc" id="{{ $formId }}">
    <div class="dr-doc__head">
        <h3 class="dr-doc__name">{{ $item['label'] }}</h3>
        <x-eh.badge :tone="$item['chip']['tone']">{{ $item['chip']['label'] }}</x-eh.badge>
    </div>
    @if ($item['reason'])
        <p class="dr-doc__reason">{{ $item['reason'] }}@if($item['review_note']). {{ $item['review_note'] }}@endif</p>
    @endif
    @if ($item['expires_on'])
        <p class="dr-note">{{ $item['satisfied'] ? 'Valid until' : 'Expiry date given' }} {{ \Illuminate\Support\Carbon::parse($item['expires_on'])->isoFormat('D MMMM YYYY') }}.</p>
    @endif
    @if ($item['state'] === 'pending_review')
        <p class="dr-note">Sent {{ $item['submitted_at']?->diffForHumans() }}. A person checks it before it counts.</p>
    @endif

    @if ($needsAction || $item['satisfied'])
        <details class="dr-docform" @if($needsAction) open @endif data-dr-typing>
            <summary class="dr-textlink">{{ $verb }}</summary>
            <form class="dr-upload" method="POST" action="{{ route('drive.documents.upload') }}" enctype="multipart/form-data" data-dr-upload data-max-image-kb="{{ $limits['image_kb'] }}" data-max-pdf-kb="{{ $limits['pdf_kb'] }}">
                @csrf
                <input type="hidden" name="type" value="{{ $item['type'] }}">
                @if ($vehicle)<input type="hidden" name="vehicle_id" value="{{ $vehicle->id }}">@endif
                @if ($item['expiry_required'])
                    <x-eh.field :label="'Expiry date on the '.strtolower($item['label'])" name="expires_on" type="date" :id="$formId.'-expiry'" size="driver" required />
                @endif
                <div class="dr-upload__pick">
                    <label class="dr-file dr-file--primary"><x-eh.icon name="document" /><span>Take a photo</span><input type="file" name="document_photo" accept="image/*" capture="environment" data-dr-file></label>
                    <label class="dr-file"><x-eh.icon name="upload" /><span>Choose a file</span><input type="file" name="document" accept="{{ $accept }}" data-dr-file></label>
                </div>
                <p class="dr-note">{{ $imageOnly ? 'A photo' : 'A photo or a PDF' }}, up to {{ intdiv($imageOnly ? $limits['image_kb'] : max($limits['image_kb'], $limits['pdf_kb']), 1024) }} MB. Location data in photos is removed before storing.</p>
                <div class="dr-progress" data-dr-progress hidden>
                    <div class="dr-progress__bar"><span></span></div>
                    <p class="dr-upload__msg" data-dr-upload-msg role="status"></p>
                </div>
                <div class="dr-actions__row">
                    <button type="submit" class="eh-btn eh-btn--primary eh-btn--driver" data-dr-upload-submit>Send</button>
                    <button type="button" class="eh-btn eh-btn--secondary eh-btn--driver" data-dr-upload-retry hidden>Try again</button>
                </div>
            </form>
        </details>
    @endif
</li>
