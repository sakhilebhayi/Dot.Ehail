<?php

namespace Tests\Concerns;

use DOMDocument;
use DOMXPath;
use Illuminate\Testing\TestResponse;

/**
 * Markup contract of the responsive app shell (layouts/app.blade.php): below 860px the sidebar
 * is an off-canvas drawer opened by a labelled top-bar button. These checks read the rendered
 * HTML, never pixels; behaviour (focus, Escape, scroll lock) lives in layouts/partials/shell-script.
 */
trait AssertsResponsiveShell
{
    protected function shellXPath(TestResponse $response): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML($response->getContent());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }

    protected function assertResponsiveShell(TestResponse $response): DOMXPath
    {
        $response->assertOk();
        $xpath = $this->shellXPath($response);

        $this->assertSame(1, $xpath->query('//meta[@name="viewport"][contains(@content, "width=device-width")]')->length, 'viewport meta');

        $button = $xpath->query('//header[contains(@class, "topbar")]//button[@data-shell-open]');
        $this->assertSame(1, $button->length, 'one menu button in the top bar');
        $this->assertSame('app-sidebar', $button->item(0)->getAttribute('aria-controls'));
        $this->assertSame('false', $button->item(0)->getAttribute('aria-expanded'));
        $this->assertSame('button', $button->item(0)->getAttribute('type'));
        $this->assertStringContainsString('Menu', preg_replace('/\s+/', ' ', $button->item(0)->textContent), 'the button carries a visible label');

        $drawer = $xpath->query('//aside[@id="app-sidebar"][@aria-label]');
        $this->assertSame(1, $drawer->length, 'drawer container');
        $this->assertGreaterThan(0, $xpath->query('//aside[@id="app-sidebar"]//nav//a[@href]')->length, 'navigation links live inside the drawer');
        $this->assertSame(1, $xpath->query('//aside[@id="app-sidebar"]//button[@data-shell-close][@aria-label="Close menu"]')->length, 'close button inside the drawer');
        $this->assertSame(1, $xpath->query('//div[contains(@class, "shell-backdrop")][@data-shell-close]')->length, 'backdrop');
        $this->assertGreaterThanOrEqual(2, $xpath->query('//*[@data-shell-behind]')->length, 'top bar and content are made inert while the drawer is open');
        $this->assertSame(1, $xpath->query('//main[@id="main-content"]')->length, 'one main landmark');

        $script = $xpath->query('//script[contains(text(), "app-sidebar")]');
        $this->assertSame(1, $script->length, 'drawer script is included once');
        $this->assertStringContainsString("event.key === 'Escape'", $script->item(0)->textContent);

        return $xpath;
    }

    protected function assertScrollsHorizontally(DOMXPath $xpath, string $label): void
    {
        $wrapper = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " table-scroll ")][@role="region"][@aria-label="'.$label.'"][@tabindex="0"]');
        $this->assertSame(1, $wrapper->length, "table region \"{$label}\" is an overflow-x container");
        $this->assertGreaterThan(0, $xpath->query('.//table', $wrapper->item(0))->length, "\"{$label}\" wraps a table");
    }
}
