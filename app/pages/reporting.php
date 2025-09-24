<?php
declare(strict_types=1);
require __DIR__.'/../../config/db.php';
require __DIR__.'/../../lib/helpers.php';

layout_header('Reporting', 'reporting');
?>
  <div class="card">
    <h1>Reporting</h1>
    <p class="hint">Wähle einen Report. Beide nutzen deine bestehenden Views und unterstützen Filter, CSV- &amp; PDF-Export.</p>
  </div>

  <div class="grid2">
    <div class="card">
      <h2>Ausgaben pro Mitarbeiter</h2>
      <p class="muted">Bewegungen vom Typ Ausgabe/Storno (601/602) – mit Mitarbeiter, Material, Farbe/Größe und Menge (mit Vorzeichen).</p>
      <a class="btn btn-primary" href="<?= e(base_url()) ?>/?p=reporting_mitarbeiter">Öffnen</a>
    </div>
    <div class="card">
      <h2>Materialbelege (alle)</h2>
      <p class="muted">Alle Buchungen (WE/WA/Inventur/Storno) – inkl. Lagerort, Referenz, „gebucht von“ und Bemerkung.</p>
      <a class="btn btn-primary" href="<?= e(base_url()) ?>/?p=reporting_belege">Öffnen</a>
    </div>
  </div>
<?php
layout_footer();
