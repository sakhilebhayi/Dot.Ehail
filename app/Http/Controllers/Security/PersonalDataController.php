<?php

namespace App\Http\Controllers\Security;

use App\Domain\Security\PersonalDataExport;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /account/data-export: the signed-in person's own data as a JSON download (POPIA s23). Needs a recent
 * sign-in (it is everything about them in one file), is rate-limited, never cached, and every export is audited.
 */
class PersonalDataController extends Controller
{
    public function export(Request $request, PersonalDataExport $export): Response
    {
        $user = $request->user();
        AuditLog::record('account.data_exported', $user, $user);

        return response()->json($export->for($user), 200, [
            'Content-Disposition' => 'attachment; filename="dot-ehail-my-data-'.now()->format('Ymd').'.json"',
            'Cache-Control' => 'no-store, private',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
