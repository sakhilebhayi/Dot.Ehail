<?php

namespace App\Http\Controllers\Ops;

use App\Domain\Ops\Audit\OpsAudit;
use App\Domain\Verification\Models\VerificationDocument;
use App\Http\Controllers\Controller;
use App\Models\DriverProfile;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Verification: the review queue (driver documents, vehicle documents), independent driver applications, the
 * document viewer (the file itself streams from the verification domain, which logs every view by a non-owner),
 * and the expiry dashboard. Decisions post to the verification API (approve, reject, request resubmission).
 */
class VerificationController extends Controller
{
    public function index(Request $request): View
    {
        $validated = $request->validate([
            'subject' => ['nullable', Rule::in(['driver', 'vehicle', 'applications'])],
            'assigned' => ['nullable', Rule::in(['me', 'unassigned', 'any'])],
        ]);
        $subject = $validated['subject'] ?? 'driver';
        $assigned = $validated['assigned'] ?? 'any';

        $pendingBySubject = VerificationDocument::query()->where('status', 'pending')
            ->selectRaw('subject, count(*) as n')->groupBy('subject')->pluck('n', 'subject');
        $applications = DriverProfile::withoutGlobalScope('user')->where('status', 'pending')->count();

        $documents = null;
        $profiles = null;
        if ($subject === 'applications') {
            $profiles = DriverProfile::withoutGlobalScope('user')->with(['user:id,name,email', 'fleet:id,name'])
                ->where('status', 'pending')->orderBy('created_at')->paginate(50)->withQueryString();
        } else {
            $documents = VerificationDocument::query()
                ->with(['owner:id,name', 'assignedReviewer:id,name', 'vehicle'])
                ->where('status', 'pending')->where('subject', $subject)
                ->when($assigned === 'me', fn ($q) => $q->where('assigned_reviewer_id', $request->user()->id))
                ->when($assigned === 'unassigned', fn ($q) => $q->whereNull('assigned_reviewer_id'))
                ->orderBy('submitted_at')->orderBy('id')
                ->paginate(50)->withQueryString();
        }

        return view('ops.verification.index', [
            'subject' => $subject,
            'assigned' => $assigned,
            'documents' => $documents,
            'profiles' => $profiles,
            'pendingBySubject' => $pendingBySubject,
            'applications' => $applications,
            'types' => (array) config('ehail_safety.markets.ZA.document_types', []),
        ]);
    }

    public function show(Request $request, VerificationDocument $document, OpsAudit $audit): View
    {
        $document->load(['owner:id,name,email', 'vehicle', 'reviews', 'assignedReviewer:id,name']);
        $audit->viewed('verification_document', $request->user(), $document);

        return view('ops.verification.show', [
            'document' => $document,
            'type' => ((array) config('ehail_safety.markets.ZA.document_types', []))[$document->type] ?? null,
            'reasons' => (array) config('ehail_safety.verification.reject_reasons', []),
            'others' => VerificationDocument::query()->where('user_id', $document->user_id)->whereKeyNot($document->id)->orderByDesc('id')->limit(20)->get(),
            'isOwn' => $document->user_id === $request->user()->id,
        ]);
    }

    public function expiry(Request $request): View
    {
        $validated = $request->validate(['within' => ['nullable', Rule::in(['7', '30', '60', '90'])]]);
        $within = (int) ($validated['within'] ?? 30);

        $expiring = VerificationDocument::query()->with(['owner:id,name', 'vehicle'])
            ->where('status', 'approved')->whereNotNull('expires_on')
            ->whereDate('expires_on', '<=', now()->addDays($within)->toDateString())
            ->orderBy('expires_on')->limit(500)->get();

        $expiredRecently = VerificationDocument::query()->with('owner:id,name')
            ->where('status', 'expired')->where('expired_at', '>=', now()->subDays(30))
            ->orderByDesc('expired_at')->limit(200)->get();

        return view('ops.verification.expiry', [
            'within' => $within,
            'expiring' => $expiring,
            'expiredRecently' => $expiredRecently,
            'types' => (array) config('ehail_safety.markets.ZA.document_types', []),
            'pendingBySubject' => VerificationDocument::query()->where('status', 'pending')->selectRaw('subject, count(*) as n')->groupBy('subject')->pluck('n', 'subject'),
            'applications' => DriverProfile::withoutGlobalScope('user')->where('status', 'pending')->count(),
        ]);
    }
}
