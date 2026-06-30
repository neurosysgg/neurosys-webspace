<?php
declare(strict_types=1);

namespace NeuroSYS\View;

class PrivacyView extends View
{
    public function __construct(private readonly string $html) {}

    public function pageTitle(): string { return 'Privacy Policy — neuro.SYS'; }

    public function content(): string
    {
        return <<<HTML
            <section class="page-section">
              {$this->html}
            </section>
            HTML;
    }
}