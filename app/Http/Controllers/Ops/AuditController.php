<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The audit log (append-only audit_logs), filterable by action prefix, actor, subject and date. */
class AuditController extends Controller
{
    public function index(Request $request): View
    {
        $v = $request->validate([
            'action' => ['nullable', 'string', 'max:60'],
            'actor' => ['nullable', 'integer'],
            'subject_type' => ['nullable', 'string', 'max:60'],
            'subject_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $rows = AuditLog::query()->with('actor:id,name')
            ->when($v['action'] ?? null, fn ($q, $a) => $q->where('action', 'like', str_replace(['%', '_'], ['\%', '\_'], $a).'%'))
            ->when($v['actor'] ?? null, fn ($q, $a) => $q->where('actor_user_id', $a))
            ->when($v['subject_type'] ?? null, fn ($q, $t) => $q->where('subject_type', $t))
            ->when($v['subject_id'] ?? null, fn ($q, $id) => $q->where('subject_id', $id))
            ->when($v['from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', $d.' 00:00:00'))
            ->when($v['to'] ?? null, fn ($q, $d) => $q->where('created_at', '<=', $d.' 23:59:59'))
            ->orderByDesc('id')
            ->paginate(100)
            ->withQueryString();

        $prefixes = AuditLog::query()->selectRaw('action')->distinct()->orderBy('action')->limit(500)->pluck('action')
            ->map(fn ($a) => explode('.', $a)[0])->unique()->values();
        $types = AuditLog::query()->select('subject_type')->distinct()->orderBy('subject_type')->limit(100)->pluck('subject_type');

        return view('ops.admin.audit', ['rows' => $rows, 'filters' => $v, 'prefixes' => $prefixes, 'types' => $types]);
    }
}
