<?php
declare(strict_types=1);

namespace NeuroSYS\View;

class ImprintView extends View
{
    public function pageTitle(): string { return 'Imprint — neuro.SYS'; }

    public function content(): string
    {
        return <<<HTML
            <section class="page-section">
              <h1>Impressum</h1>

              <h2>Angaben gemäß § 5 DDG</h2>
              <p>
                Niclas Ahl<br />
                c/o Adressgeber #2109<br />
                An der alten Ziegelei 38<br />
                48157 Münster<br />
                Germany
              </p>

              <h2>Kontakt</h2>
              <p>
                E-Mail: <a href="mailto:neuro.sys@neurosys.gg">neuro.sys@neurosys.gg</a>
              </p>

              <h2>Verantwortlicher im Sinne des § 18 Abs. 2 MStV</h2>
              <p>
                Niclas Ahl<br />
                c/o Adressgeber #2109<br />
                An der alten Ziegelei 38<br />
                48157 Münster<br />
                Germany
              </p>
            
              <h1>Imprint</h1>

              <h2>Information pursuant to § 5 DDG</h2>
              <p>
                Niclas Ahl<br />
                c/o Adressgeber #2109<br />
                An der alten Ziegelei 38<br />
                48157 Münster<br />
                Germany
              </p>

              <h2>Contact</h2>
              <p>
                E-Mail: <a href="mailto:neuro.sys@neurosys.gg">neuro.sys@neurosys.gg</a>
              </p>

              <h2>Responsible for content pursuant to § 18 Abs. 2 MStV</h2>
              <p>
                Niclas Ahl<br />
                c/o Adressgeber #2109<br />
                An der alten Ziegelei 38<br />
                48157 Münster<br />
                Germany
              </p>
            </section>
            HTML;
    }
}