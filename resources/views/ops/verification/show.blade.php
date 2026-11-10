@php
    $status = $document->status instanceof \BackedEnum ? $document->status->value : (string) $document->status;
    $label = $type['label'] ?? str_replace('_', ' ', $document->type);
    $fileUrl = route('verification.documents.file', $document->id);
    $isImage = str_starts_with((string) $document->mime, 'image/');
    $needsExpiry = (bool) ($type['expiry_required'] ?? false);
@endphp
<x-ops-layout :title="$label">
    <x-slot:head>@include('ops.partials.assets')</x-slot:head>
    <div class="ops">
        <p class="ops-crumb"><a href="{{ route('ops.verification', ['subject' => $document->subject === 'vehicle' ? 'vehicle' : null]) }}">Verification</a> / {{ $label }}</p>
        <header class="ops-head">
            <div class="ops-head__text">
                <h1>{{ $label }} <x-eh.badge :tone="match($status){'approved'=>'go','pending'=>'caution','rejected','expired'=>'stop',default=>'neutral'}">{{ ucfirst(str_replace('_', ' ', $status)) }}</x-eh.badge></h1>
                <p class="ops-head__finding">{{ $document->owner?->name }}{{ $document->vehicle ? ', vehicle '.$document->vehicle->plate_number : '' }} · uploaded {{ ($document->submitted_at ?? $document->created_at)?->copy()->setTimezone(\App\Support\Ui\LocalTime::timezone())->format('d M Y H:i') }}. Opening the file is recorded in the audit log.</p>
            </div>
            <div class="ops-head__actions">
                @can('ops.drivers.view')<x-eh.button size="sm" variant="secondary" :href="route('ops.drivers.show', $document->user_id)" icon="steering-wheel">Driver record</x-eh.button>@endcan
            </div>
        </header>

        <div class="ops-split">
            <section class="ops-panel" aria-labelledby="file-h">
                <div class="ops-panel__head"><h2 id="file-h">File</h2><span class="ops-panel__meta">{{ $document->original_filename }} · {{ $document->mime }} · {{ number_format(($document->size_bytes ?? 0) / 1024) }} KB</span></div>
                <div class="ops-doc">
                    @if ($isImage)
                        <img src="{{ $fileUrl }}" alt="{{ $label }} uploaded by {{ $document->owner?->name }}">
                    @else
                        <p class="ops-note" style="padding:var(--eh-space-6);text-align:center">PDF files open in a separate tab, in the browser's own viewer.<br><x-eh.button size="sm" :href="$fileUrl" target="_blank" rel="noopener" icon="external" style="margin-top:var(--eh-space-3)">Open the PDF</x-eh.button></p>
                    @endif
                </div>
                <p class="ops-note ops-note--small" style="margin-top:var(--eh-space-2)">Open files with care: uploads are checked for type and size but not scanned for viruses. Photos were re-saved at upload, which removed their location and camera details.</p>
            </section>

            <div class="ops-stack">
                @if ($status === 'pending' && ! $isOwn)
                    <section class="ops-panel" aria-labelledby="decide-h">
                        <div class="ops-panel__head"><h2 id="decide-h">Decide</h2><span class="ops-panel__meta">assigned to {{ $document->assignedReviewer?->name ?? 'nobody' }}</span></div>
                        <div class="ops-actions">
                            @if ($document->assigned_reviewer_id !== auth()->id())
                                <form class="ops-act" data-ops-action method="post" action="{{ route('safety.ops.verification.assign', $document->id) }}" data-ops-done="Assigned to you.">
                                    <div class="ops-act__row"><x-eh.button type="submit" size="sm" variant="secondary" icon="user">Assign to me</x-eh.button></div>
                                </form>
                            @endif
                            <details open>
                                <summary>Approve</summary>
                                <form class="ops-act" data-ops-action method="post" action="{{ route('safety.ops.verification.approve', $document->id) }}" data-ops-done="Document approved.">
                                    @if ($needsExpiry)<x-eh.field label="Expiry date on the document" name="expires_on" type="date" required id="approve-expiry" :value="$document->expires_on?->toDateString()" hint="This document type needs its expiry date." />@endif
                                    <x-eh.field label="Note" name="note" type="textarea" maxlength="1000" id="approve-note" :optional="true" />
                                    <div class="ops-act__row"><x-eh.button type="submit" size="sm" icon="check">Approve</x-eh.button></div>
                                </form>
                            </details>
                            <details>
                                <summary>Reject <span class="ops-hint">the driver must upload a new one</span></summary>
                                <form class="ops-act" data-ops-action method="post" action="{{ route('safety.ops.verification.reject', $document->id) }}" data-ops-done="Document rejected.">
                                    <x-eh.select label="Reason" name="reason" :options="$reasons" id="reject-reason" />
                                    <x-eh.field label="Note (needed for Other)" name="note" type="textarea" maxlength="1000" id="reject-note" :optional="true" />
                                    <div class="ops-act__row"><x-eh.button type="submit" size="sm" variant="danger">Reject</x-eh.button></div>
                                </form>
                            </details>
                            <details>
                                <summary>Ask for a resubmission <span class="ops-hint">a fixable problem</span></summary>
                                <form class="ops-act" data-ops-action method="post" action="{{ route('safety.ops.verification.resubmission', $document->id) }}" data-ops-done="Resubmission requested.">
                                    <x-eh.select label="Reason" name="reason" :options="$reasons" id="resub-reason" />
                                    <x-eh.field label="Note (needed for Other)" name="note" type="textarea" maxlength="1000" id="resub-note" :optional="true" />
                                    <div class="ops-act__row"><x-eh.button type="submit" size="sm" variant="secondary">Ask for a new upload</x-eh.button></div>
                                </form>
                            </details>
                        </div>
                    </section>
                @elseif ($isOwn)
                    <x-eh.banner tone="caution" title="This is your own document">Someone else must review it.</x-eh.banner>
                @endif

                <section class="ops-panel" aria-labelledby="hist-h">
                    <div class="ops-panel__head"><h2 id="hist-h">Review history</h2></div>
                    @if ($document->reviews->isEmpty())
                        <p class="ops-none" style="padding-top:0">No decision has been recorded.</p>
                    @else
                        <ol class="ops-route">
                            @foreach ($document->reviews->sortBy('id') as $review)
                                <li data-kind="{{ in_array($review->to_status, ['rejected', 'resubmission_requested', 'expired'], true) ? 'stop' : 'main' }}" @if($loop->last) data-current @endif>
                                    <span class="ops-route__stop" aria-hidden="true"></span>
                                    <span class="ops-route__what">{{ ucfirst(str_replace('_', ' ', (string) $review->action)) }}</span>
                                    <time class="ops-route__when">{{ $review->created_at?->copy()->setTimezone(\App\Support\Ui\LocalTime::timezone())->format('d M Y H:i') }}</time>
                                    <span class="ops-route__detail">{{ $review->reason ? ($reasons[$review->reason] ?? $review->reason) : '' }}{{ $review->note ? ' "'.$review->note.'"' : '' }}</span>
                                </li>
                            @endforeach
                        </ol>
                    @endif
                </section>

                <section class="ops-panel ops-panel--flush" aria-labelledby="others-h">
                    <div class="ops-panel__head"><h2 id="others-h">Other documents from this driver</h2></div>
                    @forelse ($others as $other)
                        @php($otherStatus = $other->status instanceof \BackedEnum ? $other->status->value : (string) $other->status)
                        <ul class="ops-queue"><li><a class="ops-queue__title" href="{{ route('ops.verification.show', $other->id) }}">{{ ((array) config('ehail_safety.markets.ZA.document_types'))[$other->type]['label'] ?? $other->type }}</a><span class="ops-queue__meta">{{ ucfirst(str_replace('_', ' ', $otherStatus)) }}{{ $other->expires_on ? ' · expires '.$other->expires_on->copy()->setTimezone(\App\Support\Ui\LocalTime::timezone())->format('d M Y') : '' }}</span></li></ul>
                    @empty
                        <p class="ops-none">None.</p>
                    @endforelse
                </section>
            </div>
        </div>
    </div>
</x-ops-layout>
