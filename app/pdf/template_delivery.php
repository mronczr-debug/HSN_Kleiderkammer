<?php
// app/pdf/template_delivery.php
declare(strict_types=1);

/**
 * Baut das HTML für den PDF-Beleg.
 * $company: ['name' => ..., 'addr' => ...]
 * $kopf:    Lieferung + Mitarbeiter + Bestellung
 * $pos:     Positionsliste
 * $meta:    ['isRueckgabe'=>bool, 'absender'=>string, 'logo'=>data-uri]
 */

if (!function_exists('pdf_template_delivery')) {
  function pdf_template_delivery(array $company, array $kopf, array $pos, array $meta): string
  {
    $title = $meta['isRueckgabe'] ? 'Rückgabe-Beleg' : 'Ausgabe-Beleg';
    $today = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('d.m.Y H:i');
    $summe = 0.0;
    foreach ($pos as $p) $summe += (float)$p['Menge'];

    ob_start();
    ?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <style>
    @page { margin: 22mm 16mm 20mm 16mm; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 11pt; color: #111827; }
    .header { display:flex; align-items:center; justify-content:space-between; margin-bottom: 14px; }
    .logo { height: 46px; }
    .doc-title { font-size: 16pt; font-weight: 700; }
    .company { font-size: 10pt; color:#4b5563; }
    .meta { margin-top: 6px; font-size: 9.5pt; color:#374151; }
    .box { border: 1px solid #e5e7eb; border-radius: 6px; padding: 10px 12px; margin-top: 10px; }
    .row { display:flex; gap: 16px; }
    .col { flex: 1; }
    .h { color:#6b7280; font-size: 9pt; margin-bottom: 2px; }
    .v { font-size: 11pt; font-weight: 600; }

    table { width:100%; border-collapse: collapse; margin-top: 10px; }
    th, td { border-bottom: 1px solid #e5e7eb; text-align: left; padding: 6px 6px; }
    th { background: #f3f4f6; font-weight: 700; font-size: 10pt; }
    td { font-size: 10pt; }
    .right { text-align: right; }
    .muted { color:#6b7280; }

    .totals { margin-top: 8px; text-align: right; font-weight: 700; }
    .foot { position: fixed; bottom: 10mm; left: 16mm; right: 16mm; font-size: 9pt; color:#6b7280; display:flex; justify-content:space-between; }
    .sign { margin-top: 16px; display:flex; gap: 30px; }
    .sign .line { border-top: 1px solid #9ca3af; width: 240px; text-align:center; padding-top: 4px; font-size: 9pt; color:#6b7280; }
  </style>
</head>
<body>

  <div class="header">
    <div>
      <div class="doc-title"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></div>
      <div class="company"><?= htmlspecialchars($company['name'], ENT_QUOTES, 'UTF-8') ?> • <?= htmlspecialchars($company['addr'], ENT_QUOTES, 'UTF-8') ?></div>
      <div class="meta">Erstellt am <?= htmlspecialchars($today, ENT_QUOTES, 'UTF-8') ?> UTC</div>
    </div>
    <?php if (!empty($meta['logo'])): ?>
      <img class="logo" src="<?= $meta['logo'] ?>" alt="Logo">
    <?php endif; ?>
  </div>

  <div class="box">
    <div class="row">
      <div class="col">
        <div class="h"><?= $meta['isRueckgabe'] ? 'Rückgabe-Nr.' : 'Liefer-Nr.' ?></div>
        <div class="v"><?= htmlspecialchars((string)$kopf['LieferNr'], ENT_QUOTES, 'UTF-8') ?></div>
      </div>
      <div class="col">
        <div class="h">Bestell-Nr.</div>
        <div class="v"><?= htmlspecialchars((string)$kopf['BestellNr'], ENT_QUOTES, 'UTF-8') ?></div>
      </div>
      <div class="col">
        <div class="h">Datum</div>
        <div class="v"><?= htmlspecialchars(substr((string)$kopf['LieferDatum'], 0, 10), ENT_QUOTES, 'UTF-8') ?></div>
      </div>
      <div class="col">
        <div class="h">Bearbeiter</div>
        <div class="v"><?= htmlspecialchars((string)($meta['absender'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
      </div>
    </div>

    <div class="row" style="margin-top:10px">
      <div class="col">
        <div class="h">Mitarbeiter</div>
        <div class="v">
          <?= htmlspecialchars($kopf['Nachname'].', '.$kopf['Vorname'].' ('.$kopf['Personalnummer'].')', ENT_QUOTES, 'UTF-8') ?><br>
          <span class="muted">
            <?= htmlspecialchars(($kopf['Abteilung'] ?? ''), ENT_QUOTES, 'UTF-8') ?> •
            <?= htmlspecialchars(($kopf['TypName'] ?? ''), ENT_QUOTES, 'UTF-8') ?> •
            <?= htmlspecialchars(($kopf['KatName'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
          </span>
        </div>
      </div>
      <?php if ($meta['isRueckgabe'] && !empty($kopf['RueckgabeGrund'])): ?>
        <div class="col">
          <div class="h">Rückgabegrund</div>
          <div class="v"><?= htmlspecialchars((string)$kopf['RueckgabeGrund'], ENT_QUOTES, 'UTF-8') ?></div>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <table>
    <thead>
      <tr>
        <th style="width:60px">Pos</th>
        <th>Artikel</th>
        <th style="width:140px">Farbe</th>
        <th style="width:120px">Größe</th>
        <th style="width:130px">SKU</th>
        <th class="right" style="width:110px">Menge</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($pos as $p): ?>
        <tr>
          <td><?= (int)$p['PosNr'] ?></td>
          <td>
            <strong><?= htmlspecialchars($p['MaterialName'].' – '.$p['VariantenBezeichnung'], ENT_QUOTES, 'UTF-8') ?></strong>
          </td>
          <td><?= htmlspecialchars((string)$p['Farbe'], ENT_QUOTES, 'UTF-8') ?></td>
          <td><?= htmlspecialchars((string)$p['Groesse'], ENT_QUOTES, 'UTF-8') ?></td>
          <td><?= htmlspecialchars((string)($p['SKU'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
          <td class="right"><?= number_format((float)$p['Menge'], 3, ',', '.') ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <div class="totals">Gesamtmenge: <?= number_format((float)$summe, 3, ',', '.') ?></div>

  <div class="sign">
    <div class="line">Unterschrift Mitarbeiter</div>
    <div class="line">Unterschrift Ausgabestelle</div>
  </div>

  <div class="foot">
    <div>HSN Kleiderkammer</div>
    <div>Seite <span class="pageNumber"></span> / <span class="totalPages"></span></div>
  </div>

  <script type="text/php">
    if (isset($pdf)) {
      $font = $fontMetrics->getFont("DejaVu Sans");
      $size = 9;
      $pdf->page_text(520, 810, "Seite {PAGE_NUM} / {PAGE_COUNT}", $font, $size, array(0.42,0.45,0.49));
    }
  </script>
</body>
</html>
    <?php
    return (string)ob_get_clean();
  }
}
