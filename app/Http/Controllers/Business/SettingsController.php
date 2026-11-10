<?php

namespace App\Http\Controllers\Business;

use App\Domain\Business\Models\BusinessAccount;
use App\Domain\Business\SpendLimits;
use App\Domain\Payments\Models\PaymentMethod;
use App\Services\Payments\Paystack\PaystackConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Company details and how the account pays (account admins edit; finance viewers read). Credit limit and
 * terms are set by Dot.Ehail operators and shown read-only here.
 */
class SettingsController extends BusinessController
{
    public function show(Request $request, PaystackConfig $paystack, SpendLimits $limits): View
    {
        $member = $this->member($request, 'view_statements');

        return view('business.settings', [
            'member' => $member,
            'account' => $member->account->load(['team', 'cardMethod']),
            'can' => $this->can($member),
            'cards' => PaymentMethod::where('user_id', $request->user()->id)->active()->where('type', PaymentMethod::CARD)->get()->filter->isChargeable()->values(),
            'cardPayments' => $paystack->canTakePayments(),
            'outstanding' => $limits->outstanding($member->account),
            'creditAvailable' => $limits->creditAvailable($member->account),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $member = $this->member($request, 'manage_billing');
        $data = $request->validate([
            'trading_name' => ['nullable', 'string', 'max:160'],
            'vat_number' => ['nullable', 'string', 'max:30', 'regex:/^[0-9 ]+$/'],
            'billing_email' => ['required', 'email', 'max:190'],
            'address_line1' => ['required', 'string', 'max:160'],
            'address_line2' => ['nullable', 'string', 'max:160'],
            'city' => ['required', 'string', 'max:80'],
            'postal_code' => ['nullable', 'string', 'max:12'],
        ], ['vat_number.regex' => 'A VAT number is digits only.']);

        // Legal name and registration number change only through Dot.Ehail (they were reviewed).
        $member->account->update(['billing_email' => strtolower($data['billing_email'])] + $data);

        return back()->with('success', 'Company details saved. Statements already issued keep the details they were issued with.');
    }

    public function billing(Request $request, PaystackConfig $paystack): RedirectResponse
    {
        $member = $this->member($request, 'manage_billing');
        $data = $request->validate([
            'billing_mode' => ['required', Rule::in([BusinessAccount::INVOICE, BusinessAccount::CARD])],
            'card_payment_method_id' => ['nullable', 'integer', Rule::requiredIf($request->input('billing_mode') === BusinessAccount::CARD)],
        ], ['card_payment_method_id.required' => 'Choose the company card to charge.']);

        $card = null;

        if ($data['billing_mode'] === BusinessAccount::CARD) {
            abort_unless($paystack->canTakePayments(), 422, 'Card payments are not enabled on this environment.');

            $card = PaymentMethod::where('user_id', $request->user()->id)->active()->where('type', PaymentMethod::CARD)->find($data['card_payment_method_id']);

            if ($card === null || ! $card->isChargeable()) {
                return back()->withErrors(['card_payment_method_id' => 'Choose one of your saved cards that can be charged.']);
            }
        }

        $member->account->forceFill(['billing_mode' => $data['billing_mode'], 'card_payment_method_id' => $card?->id])->save();

        return back()->with('success', $card ? 'Statements will be charged to '.$card->maskedLabel().'.' : 'Statements will be paid by EFT within the account terms.');
    }
}
