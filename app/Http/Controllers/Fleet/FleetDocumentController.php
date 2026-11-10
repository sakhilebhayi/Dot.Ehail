<?php

namespace App\Http\Controllers\Fleet;

use App\Domain\Fleet\DocumentOverview;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Document expiry overview for every driver and vehicle of the fleet: status and dates only, from verification.
 */
class FleetDocumentController extends FleetController
{
    public function __invoke(Request $request, DocumentOverview $documents): View
    {
        $fleet = $this->fleet($request, 'documents');
        $rows = collect($documents->rows($request->user(), $fleet));
        $filter = in_array($request->query('show'), ['attention', 'expiring', 'all'], true) ? $request->query('show') : 'attention';

        $visible = match ($filter) {
            'attention' => $rows->filter(fn ($r) => ! $r['satisfied'] || $r['expiring']),
            'expiring' => $rows->filter(fn ($r) => $r['expiring']),
            default => $rows,
        };

        return view('fleet.documents', [
            'fleet' => $fleet,
            'fleets' => $this->access->fleetsFor($request->user()),
            'rows' => $visible->sortBy(fn ($r) => [$r['satisfied'] ? 1 : 0, $r['days_left'] ?? 99999, $r['subject_label']])->values(),
            'counts' => [
                'blocking' => $rows->where('satisfied', false)->count(),
                'expiring' => $rows->where('expiring', true)->count(),
                'in_order' => $rows->filter(fn ($r) => $r['satisfied'] && ! $r['expiring'])->count(),
                'all' => $rows->count(),
            ],
            'filter' => $filter,
        ]);
    }
}
