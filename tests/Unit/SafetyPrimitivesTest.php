<?php

namespace Tests\Unit;

use App\Domain\Safety\IncidentStatus;
use App\Domain\Safety\Sms\BulkSmsGateway;
use App\Domain\Support\ContactDetailRedactor;
use App\Domain\Support\SupportStatus;
use App\Domain\Verification\DocumentStatus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SafetyPrimitivesTest extends TestCase
{
    public function test_document_state_machine_only_allows_listed_edges(): void
    {
        $this->assertTrue(DocumentStatus::Pending->canBecome(DocumentStatus::Approved));
        $this->assertFalse(DocumentStatus::Rejected->canBecome(DocumentStatus::Approved));
        $this->assertFalse(DocumentStatus::Expired->canBecome(DocumentStatus::Approved));
        $this->assertSame([], DocumentStatus::Superseded->allowedNext());

        foreach (DocumentStatus::cases() as $status) {
            $this->assertNotContains($status, $status->allowedNext(), 'no self-loops');
        }
    }

    public function test_incident_and_support_state_machines(): void
    {
        $this->assertFalse(IncidentStatus::Open->canBecome(IncidentStatus::Closed));
        $this->assertTrue(IncidentStatus::Resolved->canBecome(IncidentStatus::Closed));
        $this->assertSame([], IncidentStatus::Closed->allowedNext());
        $this->assertFalse(SupportStatus::New->canBecome(SupportStatus::WaitingOnUser));
        $this->assertSame([], SupportStatus::Closed->allowedNext());
    }

    public function test_redactor_masks_phone_numbers_and_emails_but_keeps_ordinary_numbers(): void
    {
        $redactor = new ContactDetailRedactor;

        $this->assertSame('Call [number hidden] now', $redactor->redact('Call 082 555 1234 now'));
        $this->assertSame('Or [number hidden]', $redactor->redact('Or +27 (83) 111-2222'));
        $this->assertSame('Mail [email hidden]', $redactor->redact('Mail a.b@example.co.za'));
        $this->assertSame('Seat 3, R80, 25 min', $redactor->redact('Seat 3, R80, 25 min'));
    }

    public function test_bulksms_is_off_without_credentials_and_refuses_bad_numbers_without_calling_out(): void
    {
        Http::fake();

        $off = new BulkSmsGateway([]);
        $this->assertFalse($off->isConfigured());
        $this->assertSame('skipped', $off->send('+27821234567', 'x')->status);

        $on = new BulkSmsGateway(['token_id' => 'a', 'token_secret' => 'b']);
        $this->assertSame('invalid_number', $on->send('0821234567', 'x')->error);
        Http::assertNothingSent();
    }
}
