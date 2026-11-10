<?php

namespace Tests\Feature\Ehail\Ds;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

/** ds (R-46): the <x-eh.*> kit renders accessible, honest markup. */
class ComponentsTest extends TestCase
{
    private function render(string $template, array $data = []): string
    {
        view()->share('errors', $data['__errors'] ?? new ViewErrorBag);
        unset($data['__errors']);

        return Blade::render($template, $data);
    }

    public function test_buttons_cover_links_sizes_and_states(): void
    {
        $html = $this->render('<x-eh.button size="driver" href="/drive">Accept</x-eh.button>');
        $this->assertStringContainsString('<a href="/drive"', $html);
        $this->assertStringContainsString('eh-btn--driver', $html);

        $html = $this->render('<x-eh.button :loading="true">Requesting</x-eh.button>');
        $this->assertStringContainsString('aria-busy="true"', $html);
        $this->assertStringContainsString('type="button"', $html);

        $html = $this->render('<x-eh.button href="/x" :disabled="true">Not yet</x-eh.button>');
        $this->assertStringContainsString('aria-disabled="true"', $html);
        $this->assertStringNotContainsString('href="/x"', $html);

        $html = $this->render('<x-eh.icon-button icon="close" label="Close" />');
        $this->assertStringContainsString('aria-label="Close"', $html);
        $this->assertStringContainsString('aria-hidden="true"', $html, 'the icon itself is decorative');
    }

    public function test_fields_tie_errors_and_hints_to_the_input(): void
    {
        $errors = (new ViewErrorBag)->put('default', new MessageBag(['email' => ['Enter a valid email address.']]));
        $html = $this->render('<x-eh.field label="Email" name="email" type="email" hint="We send receipts here." />', ['__errors' => $errors]);
        $this->assertStringContainsString('for="f-email"', $html);
        $this->assertStringContainsString('aria-invalid="true"', $html);
        $this->assertStringContainsString('aria-describedby="f-email-hint f-email-error"', $html);
        $this->assertStringContainsString('Enter a valid email address.', $html);

        $html = $this->render('<x-eh.switch label="Share trips with my contacts" name="share" :checked="true" />');
        $this->assertStringContainsString('role="switch"', $html);
        $this->assertStringContainsString('checked', $html);

        $html = $this->render('<x-eh.segmented legend="Ride type" name="cat" :options="[\'go\' => \'Go\', \'xl\' => \'XL\']" value="xl" />');
        $this->assertStringContainsString('<legend>Ride type</legend>', $html);
        $this->assertMatchesRegularExpression('/value="xl"\s+checked/', $html);
    }

    public function test_money_eta_and_rating_are_readable(): void
    {
        $html = $this->render('<x-eh.money :minor="-4000" />');
        $this->assertStringContainsString('value="-4000"', $html);
        $this->assertStringContainsString('aria-label="minus R40.00"', $html);
        $this->assertStringContainsString('eh-money--negative', $html);

        $html = $this->render('<x-eh.eta :seconds="240" at="2026-11-10 14:32:00" />');
        $this->assertStringContainsString('aria-label="4 minutes, arriving at 14:32"', $html);

        $html = $this->render('<x-eh.rating-stars :value="4.6" :count="12" />');
        $this->assertStringContainsString('aria-label="Rated 4.6 out of 5 from 12 ratings"', $html);
        $this->assertStringContainsString('width: 92%', $html);
    }

    public function test_status_badges_map_old_and_new_lifecycle_names(): void
    {
        $this->assertStringContainsString('Driver assigned', $this->render('<x-eh.status status="accepted" />'));
        $this->assertStringContainsString('Driver assigned', $this->render('<x-eh.status status="driver_assigned" />'));
        $this->assertStringContainsString('Driver at pickup', $this->render('<x-eh.status status="arrived" />'));
        $this->assertStringContainsString('No driver found', $this->render('<x-eh.status status="expired" />'));
        $this->assertStringContainsString('eh-badge--stop', $this->render('<x-eh.status status="needs_review" />'));
    }

    public function test_stat_line_prints_zero_and_marks_missing_values(): void
    {
        $html = $this->render('<x-eh.stat-line :items="[[\'label\' => \'rides today\', \'value\' => 0], [\'label\' => \'acceptance\', \'value\' => null]]" />');
        $this->assertStringContainsString('<dd>0</dd>', $html);
        $this->assertStringContainsString('not available', $html);
    }

    public function test_safety_banner_and_map_frame_contracts(): void
    {
        $html = $this->render('<x-eh.banner tone="safety" title="Need help?" />');
        foreach (['tel:10111', 'tel:10177', 'tel:112'] as $tel) {
            $this->assertStringContainsString('href="'.$tel.'"', $html);
        }

        $html = $this->render('<x-eh.map-frame id="pickup-map" label="Pickup map" :height="320" />');
        $this->assertStringContainsString('data-eh-map', $html);
        $this->assertStringContainsString('data-map-state="pending"', $html);
        $this->assertStringContainsString('data-eh-map-canvas', $html);
        $this->assertStringContainsString('aria-label="Pickup map"', $html);
    }

    public function test_sheet_dialog_tabs_stepper_and_avatar(): void
    {
        $html = $this->render('<x-eh.sheet id="book" title="Where to?">Body</x-eh.sheet>');
        $this->assertStringContainsString('data-eh-sheet', $html);
        $this->assertStringContainsString('data-eh-sheet-handle', $html);
        $this->assertStringContainsString('aria-expanded="false"', $html);

        $html = $this->render('<x-eh.dialog id="cancel" title="Cancel this ride?">Body</x-eh.dialog>');
        $this->assertStringContainsString('<dialog id="cancel"', $html);
        $this->assertStringContainsString('aria-labelledby="cancel-title"', $html);

        $html = $this->render('<x-eh.tabs id="t" :items="[\'a\' => \'Upcoming\', \'b\' => \'Past\']" active="a" />');
        $this->assertStringContainsString('role="tablist"', $html);
        $this->assertStringContainsString('aria-selected="true"', $html);

        $html = $this->render('<x-eh.stepper :current="1" :steps="[[\'label\' => \'Requested\'], [\'label\' => \'Driver on the way\'], [\'label\' => \'On the trip\']]" />');
        $this->assertStringContainsString('aria-current="step"', $html);
        $this->assertSame(1, substr_count($html, 'data-state="done"'));

        $html = $this->render('<x-eh.avatar name="Thandi Nkosi" src="https://ui-avatars.com/api/x" />');
        $this->assertStringContainsString('>TN<', $html);
        $this->assertStringNotContainsString('ui-avatars.com', $html, 'no images from other hosts');
    }
}
