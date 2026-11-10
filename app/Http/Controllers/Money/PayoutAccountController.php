<?php

namespace App\Http\Controllers\Money;

use App\Domain\Earnings\Models\PayoutAccount;
use App\Domain\Earnings\Payouts\PayoutAccounts;
use App\Http\Controllers\Controller;
use App\Models\Fleet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PayoutAccountController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'accounts' => PayoutAccount::where('user_id', $request->user()->id)->orderByDesc('is_active')->orderByDesc('id')->get()->map->toPublicArray()->values(),
        ]);
    }

    public function store(Request $request, PayoutAccounts $accounts): JsonResponse
    {
        $data = $request->validate([
            'payee_type' => ['required', Rule::in(['driver', 'fleet_owner'])],
            'fleet_id' => ['nullable', 'integer', 'required_if:payee_type,fleet_owner'],
            'account_holder' => ['required', 'string', 'max:120'],
            'account_number' => ['required', 'string', 'regex:/^[0-9 ]{6,20}$/'],
            'bank_code' => ['required', 'string', 'max:20', 'regex:/^[0-9A-Za-z]+$/'],
            'bank_name' => ['required', 'string', 'max:80'],
            'account_type' => ['required', Rule::in(['personal', 'business'])],
        ]);

        if ($data['payee_type'] === 'fleet_owner') {
            abort_unless(Fleet::whereKey($data['fleet_id'])->where('owner_user_id', $request->user()->id)->exists(), 403);
        } else {
            $data['fleet_id'] = null;
        }

        $account = $accounts->add($request->user(), $data);

        return response()->json(['account' => $account->toPublicArray()], 201);
    }

    public function verify(Request $request, PayoutAccount $account, PayoutAccounts $accounts): JsonResponse
    {
        abort_unless($account->user_id === $request->user()->id, 404);

        $data = $request->validate([
            'document_type' => ['required', Rule::in(['identityNumber', 'passportNumber', 'businessRegistrationNumber'])],
            'document_number' => ['required', 'string', 'max:40'],
        ]);

        return response()->json(['account' => $accounts->verify($account, $data['document_type'], $data['document_number'])->toPublicArray()]);
    }
}
