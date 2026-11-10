{{-- Simple pager for a LengthAwarePaginator: counts and previous/next, keeps the filters. --}}
@if ($page->total() > 0)
    <div class="ops-panel__foot" style="padding:0 var(--eh-space-5) var(--eh-space-4);justify-content:space-between">
        <span class="ops-stamp">{{ number_format($page->firstItem()) }} to {{ number_format($page->lastItem()) }} of {{ number_format($page->total()) }}</span>
        <span class="ops-act__row">
            @if ($page->previousPageUrl())<x-eh.button size="sm" variant="ghost" :href="$page->previousPageUrl()" icon="chevron-left">Previous</x-eh.button>@endif
            @if ($page->nextPageUrl())<x-eh.button size="sm" variant="ghost" :href="$page->nextPageUrl()" icon-right="chevron-right">Next</x-eh.button>@endif
        </span>
    </div>
@endif
