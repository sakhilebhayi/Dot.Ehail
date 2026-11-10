@php($done = in_array($case->status->value, ['resolved', 'closed'], true))
<x-ops-layout :title="$case->reference">
    <x-slot:head>@include('ops.partials.assets')</x-slot:head>
    <div class="ops">
        <p class="ops-crumb"><a href="{{ route('ops.support') }}">Support</a> / {{ $case->reference }}</p>
        <header class="ops-head">
            <div class="ops-head__text">
                <h1>{{ $case->subject }}</h1>
                <p class="ops-head__finding">{{ $case->reference }} · {{ $case->category->label() }} · {{ ucfirst(str_replace('_', ' ', $case->status->value)) }} · @include('ops.partials.sla')</p>
            </div>
            @if ($canAct && ! $done && $case->owner_id !== auth()->id())
                <form class="ops-head__actions" data-ops-action method="post" action="{{ route('support.ops.cases.assign', $case->id) }}" data-ops-done="The case is yours.">
                    <x-eh.button type="submit" size="sm" variant="secondary" icon="user">Take this case</x-eh.button>
                </form>
            @endif
        </header>
        <div class="ops-split">
            <div class="ops-stack">
                <section class="ops-panel" aria-labelledby="thread-h">
                    <div class="ops-panel__head"><h2 id="thread-h">Conversation</h2><span class="ops-panel__meta">internal notes are tinted and never shown to users</span></div>
                    @if ($case->messages->isEmpty())
                        <p class="ops-none">No message yet.</p>
                    @else
                        <ol class="ops-thread">
                            @foreach ($case->messages->sortBy('id') as $m)
                                <li data-visibility="{{ $m->visibility }}" data-author="{{ $m->author_role }}">
                                    <div class="ops-thread__meta"><strong>{{ $m->author?->name ?? 'System' }}</strong><span>{{ $m->author_role }}</span><span>{{ $m->visibility === 'internal' ? 'internal note' : ($m->visibility === 'participants' ? 'to everyone on the case' : 'to the requester') }}</span><time>{{ $m->created_at?->copy()->setTimezone(\App\Support\Ui\LocalTime::timezone())->format('d M H:i') }}</time></div>
                                    @if ($m->body)<p>{{ $m->body }}</p>@endif
                                    @if ($m->attachment_name)<p><a class="eh-link" href="{{ route('support.attachments.show', $m->id) }}" target="_blank" rel="noopener">{{ $m->attachment_name }}</a> <span class="ops-stamp">opening it is logged</span></p>@endif
                                </li>
                            @endforeach
                        </ol>
                    @endif
                    @if ($canAct && ! $done)
                        <form class="ops-act" data-ops-action method="post" action="{{ route('support.ops.cases.reply', $case->id) }}" enctype="multipart/form-data" data-ops-done="Reply sent." style="margin-top:var(--eh-space-4)">
                            <x-eh.field label="Your reply" name="body" type="textarea" maxlength="5000" id="reply-body" />
                            <div class="eh-field"><label class="eh-field__label" for="reply-file">Attach a file <span class="eh-field__optional">(optional; image or PDF)</span></label><input id="reply-file" type="file" name="attachment" accept="image/*,application/pdf" style="font:inherit"></div>
                            <div class="ops-act__row">
                                <div class="eh-select-wrap" style="width:auto"><label class="eh-sr-only" for="reply-vis">Who sees it</label><select id="reply-vis" class="eh-select" name="visibility" style="padding-right:var(--eh-space-10)"><option value="requester">The requester</option>@if($case->counterparty_user_id)<option value="participants">Everyone on the case</option>@endif<option value="internal">Internal note</option></select><x-eh.icon name="chevron-down" /></div>
                                <label class="ops-check"><input type="checkbox" name="await_user" value="1"> Wait for their answer</label>
                                <x-eh.button type="submit" size="sm">Send</x-eh.button>
                            </div>
                        </form>
                    @endif
                </section>
            </div>
            <div class="ops-stack">
                <section class="ops-panel" aria-labelledby="case-h">
                    <div class="ops-panel__head"><h2 id="case-h">Case</h2></div>
                    <dl class="ops-facts">
                        <dt>From</dt><dd>{{ $case->requester?->name }} ({{ $case->requester_role }})</dd>
                        @if ($counterparty)<dt>Other party</dt><dd>{{ $counterparty->name }} <span class="ops-stamp">sees "the {{ $case->requester_role === 'driver' ? 'driver' : 'passenger' }}", contact details masked</span></dd>@endif
                        <dt>Owner</dt><dd>{{ $case->owner?->name ?? 'Nobody' }}</dd>
                        <dt>Priority</dt><dd>{{ ucfirst($case->priority->value) }}</dd>
                        <dt>Opened</dt><dd class="num">{{ $case->created_at->copy()->setTimezone(\App\Support\Ui\LocalTime::timezone())->format('d M Y H:i') }}</dd>
                        <dt>First reply</dt><dd class="num">{{ $case->first_responded_at?->copy()->setTimezone(\App\Support\Ui\LocalTime::timezone())->format('d M Y H:i') ?? 'not yet' }}</dd>
                        @if ($case->ride_id)<dt>Ride</dt><dd>@can('ops.rides.view')<a class="eh-link" href="{{ route('ops.rides.show', $case->ride_id) }}">Ride {{ $case->ride_id }}</a>@else Ride {{ $case->ride_id }} @endcan</dd>@endif
                        @if ($payment)<dt>Payment</dt><dd>@can('ops.finance.view')<a class="eh-link" href="{{ route('ops.finance.payment', $payment->id) }}">{{ $payment->reference }}</a>@else{{ $payment->reference }}@endcan · {{ $payment->status }}</dd>@endif
                        @if ($case->safety_incident_id)<dt>Incident</dt><dd>@can('ops.safety.view')<a class="eh-link" href="{{ route('ops.safety.show', $case->safety_incident_id) }}">{{ $case->incident?->reference }}</a>@else{{ $case->incident?->reference }}@endcan</dd>@endif
                        @if (isset($case->meta['fare_review']))<dt>Fare review</dt><dd>{{ str_replace('_', ' ', (string) ($case->meta['fare_review']['status'] ?? 'requested')) }}</dd>@endif
                        @if ($case->resolution_code)<dt>Resolution</dt><dd>{{ $resolutionCodes[$case->resolution_code] ?? $case->resolution_code }}{{ $case->resolution_note ? ': '.$case->resolution_note : '' }}</dd>@endif
                    </dl>
                </section>
                @if ($canAct && ! $done)
                    <section class="ops-panel" aria-labelledby="cact-h">
                        <div class="ops-panel__head"><h2 id="cact-h">Move it on</h2></div>
                        <div class="ops-actions">
                            <details open>
                                <summary>Change status</summary>
                                <form class="ops-act" data-ops-action method="post" action="{{ route('support.ops.cases.status', $case->id) }}" data-ops-done="Status changed.">
                                    <x-eh.select label="New status" name="status" :options="$statuses->mapWithKeys(fn ($s) => [$s->value => ucfirst(str_replace('_', ' ', $s->value))])->all()" id="case-status" />
                                    <x-eh.select label="Resolution (needed to resolve)" name="resolution_code" :options="$resolutionCodes" placeholder="None" id="case-res" />
                                    <x-eh.field label="Resolution note" name="resolution_note" type="textarea" maxlength="2000" id="case-res-note" :optional="true" />
                                    <div class="ops-act__row"><x-eh.button type="submit" size="sm">Save status</x-eh.button></div>
                                </form>
                            </details>
                            <details>
                                <summary>Assign</summary>
                                <form class="ops-act" data-ops-action method="post" action="{{ route('support.ops.cases.assign', $case->id) }}" data-ops-done="Case assigned.">
                                    <x-eh.select label="Owner" name="owner_id" :options="$operators->pluck('name', 'id')->all()" :value="(string) ($case->owner_id ?? auth()->id())" id="case-owner" />
                                    <div class="ops-act__row"><x-eh.button type="submit" size="sm" variant="secondary">Assign</x-eh.button></div>
                                </form>
                            </details>
                            <details>
                                <summary>Priority</summary>
                                <form class="ops-act" data-ops-action method="post" action="{{ route('support.ops.cases.priority', $case->id) }}" data-ops-done="Priority changed.">
                                    <x-eh.select label="Priority" name="priority" :options="['low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'urgent' => 'Urgent']" :value="$case->priority->value" id="case-prio" />
                                    <div class="ops-act__row"><x-eh.button type="submit" size="sm" variant="secondary">Save priority</x-eh.button></div>
                                </form>
                            </details>
                            <details>
                                <summary>Escalate</summary>
                                <form class="ops-act" data-ops-action method="post" action="{{ route('support.ops.cases.escalate', $case->id) }}" data-ops-done="Escalated.">
                                    <x-eh.field label="Why" name="reason" type="text" required maxlength="200" id="case-esc" />
                                    <div class="ops-act__row"><x-eh.button type="submit" size="sm" variant="danger">Escalate</x-eh.button></div>
                                </form>
                            </details>
                        </div>
                    </section>
                @endif
            </div>
        </div>
    </div>
</x-ops-layout>
