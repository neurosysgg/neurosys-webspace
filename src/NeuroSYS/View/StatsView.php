<?php
declare(strict_types=1);

namespace NeuroSYS\View;

/**
 * The StatsView class. Renders the download statistics admin page.
 */
class StatsView extends View
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param int                    $total    Total number of logged downloads.
     * @param array<string, int>     $byFormat Download counts keyed by "slug/format".
     * @param array<string, int>     $byDay    Download counts keyed by date (YYYY-MM-DD).
     * @param bool                   $loggingEnabled Whether download logging is switched on at all.
     */
    public function __construct(
        private readonly int   $total,
        private readonly array $byFormat,
        private readonly array $byDay,
        private readonly bool  $loggingEnabled = false,
    ) {}

    public function pageTitle(): string { return 'stats — neuro.SYS'; }

    public function content(): string
    {
        // Distinguish "switched off" from "on, but nothing yet" — otherwise an empty page reads as a bug.
        if (!$this->loggingEnabled) {
            return '<section class="page-section"><p class="muted">Download logging is switched off — '
                 . 'nothing is recorded.</p></section>';
        }

        if ($this->total === 0) {
            return '<section class="page-section"><p class="muted">No downloads logged yet.</p></section>';
        }

        $total       = $this->total;
        $formatTable = $this->buildTable($this->byFormat);
        $days        = $this->byDay;
        ksort($days);
        $dayTable = $this->buildTable($days);

        return <<<HTML
            <section class="page-section">
              <h2 class="page-heading">stats</h2>
              <p class="muted">total downloads: <strong>$total</strong></p>

              <h3 class="stats-sub">by format</h3>
              $formatTable

              <h3 class="stats-sub">by day</h3>
              $dayTable
            </section>
            HTML;
    }

    private function buildTable(array $rows): string
    {
        $html = '<table class="stats-table">';
        foreach ($rows as $key => $count) {
            $html .= '<tr><td>' . htmlspecialchars((string)$key) . '</td>'
                   . '<td class="stats-count">' . $count . '</td></tr>';
        }
        return $html . '</table>';
    }
}
