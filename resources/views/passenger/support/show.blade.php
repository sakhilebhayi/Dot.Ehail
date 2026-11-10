{{-- One help case and its messages; replies post to the support API. --}}
<x-passenger-layout :title="'Case '.$case->reference">
    <x-slot:head>@vite(['resources/css/passenger.css', 'resources/js/passenger/pages.js'])</x-slot:head>
    <x-slot:header>
        <div class="eh-pagehead__text"><h1 class="eh-h1" data-pax-case-subject>Case {{ $case->reference }}</h1><p class="eh-muted" style="margin:0" data-pax-case-meta></p></div>
        <x-eh.button variant="ghost" icon="arrow-left" :href="route('ride.support')">All cases</x-eh.button>
    </x-slot:header>
    <div class="pax-page" data-pax-page="support-show">
        <section aria-labelledby="case-thread">
            <h2 id="case-thread">Messages</h2>
            <ol class="pax-thread" data-pax-thread aria-live="polite" aria-busy="true"><li><x-eh.skeleton :lines="3" label="Loading messages" /></li></ol>
        </section>
        <section aria-labelledby="case-reply">
            <h2 id="case-reply">Reply</h2>
            <form class="eh-panel pax-form" data-pax-reply>
                <x-eh.field label="Message" name="body" type="textarea" id="reply-body" />
                <div class="eh-field">
                    <label class="eh-field__label" for="reply-file">Photo or document <span class="eh-field__optional">optional</span></label>
                    <input id="reply-file" name="attachment" type="file" accept="image/jpeg,image/png,image/webp,application/pdf">
                </div>
                <p class="pax-field__status" data-pax-reply-status role="status"></p>
                <div><x-eh.button type="submit">Send</x-eh.button></div>
            </form>
        </section>
    </div>
    <script type="application/json" id="pax-page-data">@json(['urls' => ['show' => route('support.cases.show', $case), 'reply' => route('support.cases.reply', $case)], 'me' => auth()->id()])</script>
</x-passenger-layout>
