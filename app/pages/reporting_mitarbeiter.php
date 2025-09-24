<?php
declare(strict_types=1);

require __DIR__.'/../../config/db.php';
require __DIR__.'/../../lib/helpers.php';

/* ========= Helpers ========= */
function fmt_qty($n): string {
  if ($n === null) return '';
  $s = number_format((float)$n, 3, '.', '');
  return rtrim(rtrim($s, '0'), '.');
}

/* ========= Filter ========= */
$von = $_GET['von'] ?? '';
$bis = $_GET['bis'] ?? '';
$mitarbeiter = isset($_GET['mitarbeiter']) && ctype_digit((string)$_GET['mitarbeiter']) ? (int)$_GET['mitarbeiter'] : null;
$gruppe = isset($_GET['gruppe']) && ctype_digit((string)$_GET['gruppe']) ? (int)$_GET['gruppe'] : null;
$hersteller = isset($_GET['hersteller']) && ctype_digit((string)$_GET['hersteller']) ? (int)$_GET['hersteller'] : null;
$material = isset($_GET['material']) && ctype_digit((string)$_GET['material']) ? (int)$_GET['material'] : null;
$variante = isset($_GET['variante']) && ctype_digit((string)$_GET['variante']) ? (int)$_GET['variante'] : null;
$farbe = trim((string)($_GET['farbe'] ?? ''));
$groesse = trim((string)($_GET['groesse'] ?? ''));
$belegnr = trim((string)($_GET['belegnr'] ?? ''));
$createdby = trim((string)($_GET['createdby'] ?? ''));
$bem = trim((string)($_GET['bem'] ?? ''));

/* ========= Paging ========= */
$page = max(1, (int)($_GET['page'] ?? 1));
$pageSize = 25;
$offset = ($page-1) * $pageSize;

/* ========= WHERE-Bedingungen ========= */
$where = [];
$par = [];
if ($von !== '') { $where[] = 'm.Buchungsdatum >= :von'; $par[':von'] = $von; }
if ($bis !== '') { $where[] = 'm.Buchungsdatum < DATEADD(day, 1, :bis)'; $par[':bis'] = $bis; }
if ($mitarbeiter) { $where[] = 'm.MitarbeiterID = :mid'; $par[':mid'] = $mitarbeiter; }
if ($gruppe) { $where[] = 'm.Materialgruppe = (SELECT Gruppenname FROM dbo.Materialgruppe WHERE MaterialgruppeID=:gid)'; $par[':gid'] = $gruppe; }
if ($hersteller) { $where[] = 'm.HerstellerName = (SELECT Name FROM dbo.Hersteller WHERE HerstellerID=:hid)'; $par[':hid'] = $hersteller; }
if ($material) { $where[] = 'm.MaterialID = :mat'; $par[':mat'] = $material; }
if ($variante) { $where[] = 'm.VarianteID = :vid'; $par[':vid'] = $variante; }
if ($farbe !== '') { $where[] = "ISNULL(m.Farbe, N'') = :farbe"; $par[':farbe'] = $farbe; }
if ($groesse !== '') { $where[] = "ISNULL(m.Groesse, N'') = :groesse"; $par[':groesse'] = $groesse; }
if ($belegnr !== '') { $where[] = 'm.BelegNr LIKE :bnr'; $par[':bnr'] = '%'.$belegnr.'%'; }
if ($createdby !== '') { $where[] = "(ISNULL(m.CreatedBy, N'') LIKE :cb OR ISNULL(m.CreatedByName, N'') LIKE :cb)"; $par[':cb'] = '%'.$createdby.'%'; }
if ($bem !== '') { $where[] = "ISNULL(m.Bemerkung, N'') LIKE :bem"; $par[':bem'] = '%'.$bem.'%'; }
$whereSql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

/* ========= Export-Handling (keine Ausgabe davor!) ========= */
$export = $_GET['export'] ?? '';
if ($export === 'csv') {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="ausgaben_mitarbeiter.csv"');
  $out = fopen('php://output', 'w');
  // DE/Excel: Semikolon
  fputcsv($out, ['Buchungsdatum','BelegNr','Personalnr','Nachname','Vorname','Material','Variante','Farbe','Größe','Menge (±)','Gebucht von','Bemerkung'], ';');

  $sqlAll = "SELECT m.* FROM dbo.vw_Report_Ausgaben_Mitarbeiter m $whereSql ORDER BY m.Buchungsdatum DESC, m.BelegID DESC";
  $stall = $pdo->prepare($sqlAll);
  $stall->execute($par);
  while ($r = $stall->fetch(PDO::FETCH_ASSOC)) {
    fputcsv($out, [
      $r['Buchungsdatum'],
      $r['BelegNr'],
      $r['Personalnummer'],
      $r['Nachname'],
      $r['Vorname'],
      $r['MaterialName'],
      $r['VariantenBezeichnung'],
      $r['Farbe'],
      $r['Groesse'],
      fmt_qty($r['MengeSigned']),
      ($r['CreatedByName'] ?: $r['CreatedBy']),
      $r['Bemerkung'],
    ], ';');
  }
  fclose($out);
  exit;
}
if ($export === 'pdf') {
  require_once __DIR__.'/../../vendor/autoload.php';
  $base = rtrim(base_url(), '/');

  $sqlAll = "SELECT m.* FROM dbo.vw_Report_Ausgaben_Mitarbeiter m $whereSql ORDER BY m.Buchungsdatum DESC, m.BelegID DESC";
  $stall = $pdo->prepare($sqlAll);
  $stall->execute($par);
  $all = $stall->fetchAll(PDO::FETCH_ASSOC);

  // Summen für PDF
  $stsum = $pdo->prepare("SELECT SUM(m.MengeSigned) AS sumSigned FROM dbo.vw_Report_Ausgaben_Mitarbeiter m $whereSql");
  $stsum->execute($par);
  $sumSigned = $stsum->fetch(PDO::FETCH_ASSOC)['sumSigned'] ?? 0;

  ob_start(); ?>
  <html><head><meta charset="utf-8"><style>
    body{font-family:DejaVu Sans, sans-serif;font-size:12px}
    h1{font-size:16px;margin:0 0 6px 0}
    .muted{color:#555}
    table{width:100%;border-collapse:collapse;margin-top:8px}
    th,td{border:1px solid #ddd;padding:5px 6px}
    th{background:#f2f2f2}
    .right{text-align:right}
  </style></head><body>
    <table style="width:100%;border:0">
      <tr>
        <td style="border:0"><img src="<?= e($base) ?>/assets/logo.png" style="height:28px"></td>
        <td style="border:0; text-align:right"><strong>HSN Kleiderkammer</strong><br><span class="muted"><?= e(date('d.m.Y H:i')) ?></span></td>
      </tr>
    </table>
    <h1>Report: Ausgaben pro Mitarbeiter</h1>
    <div class="muted">
      <?php
        $f=[];
        if($von) $f[]='Von '.$von;
        if($bis) $f[]='Bis '.$bis;
        if($mitarbeiter){ $f[]='MitarbeiterID '.$mitarbeiter; }
        if($gruppe){ $f[]='Gruppe-ID '.$gruppe; }
        if($hersteller){ $f[]='Hersteller-ID '.$hersteller; }
        if($material){ $f[]='Material-ID '.$material; }
        if($variante){ $f[]='Variante-ID '.$variante; }
        if($farbe!==''){ $f[]='Farbe '.$farbe; }
        if($groesse!==''){ $f[]='Größe '.$groesse; }
        if($belegnr!==''){ $f[]='BelegNr~'.$belegnr; }
        if($createdby!==''){ $f[]='Gebucht von~'.$createdby; }
        if($bem!==''){ $f[]='Bemerkung~'.$bem; }
        echo 'Filter: '.e(implode(' | ', $f) ?: '– keine –');
      ?>
    </div>
    <table>
      <thead>
        <tr>
          <th>Datum</th><th>BelegNr</th><th>Personalnr</th><th>Name</th>
          <th>Material</th><th>Variante</th><th>Farbe</th><th>Größe</th>
          <th class="right">Menge (±)</th><th>Gebucht von</th><th>Bemerkung</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach($all as $r): ?>
          <tr>
            <td><?= e(substr($r['Buchungsdatum'],0,19)) ?></td>
            <td><?= e($r['BelegNr']) ?></td>
            <td><?= e($r['Personalnummer']) ?></td>
            <td><?= e($r['Nachname'].', '.$r['Vorname']) ?></td>
            <td><?= e($r['MaterialName']) ?></td>
            <td><?= e($r['VariantenBezeichnung']) ?></td>
            <td><?= e($r['Farbe']) ?></td>
            <td><?= e($r['Groesse']) ?></td>
            <td class="right"><?= e(fmt_qty($r['MengeSigned'])) ?></td>
            <td><?= e($r['CreatedByName'] ?: $r['CreatedBy']) ?></td>
            <td><?= e($r['Bemerkung']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <p><strong>Summe Menge (±):</strong> <?= e(fmt_qty($sumSigned)) ?></p>
  </body></html>
  <?php
  $html = ob_get_clean();

  $dompdf = new Dompdf\Dompdf(['isRemoteEnabled'=>true, 'isHtml5ParserEnabled'=>true]);
  $dompdf->loadHtml($html, 'UTF-8');
  $dompdf->setPaper('A4', 'landscape');
  $dompdf->render();
  $dompdf->stream('report_ausgaben_mitarbeiter.pdf', ['Attachment'=>true]);
  exit;
}

/* ========= Count / Daten / Summen ========= */
$st = $pdo->prepare("SELECT COUNT(*) AS c FROM dbo.vw_Report_Ausgaben_Mitarbeiter m $whereSql");
$st->execute($par);
$total = (int)$st->fetch(PDO::FETCH_ASSOC)['c'];
$pages = max(1, (int)ceil($total / $pageSize));

$sql = "
  SELECT m.*
  FROM dbo.vw_Report_Ausgaben_Mitarbeiter m
  $whereSql
  ORDER BY m.Buchungsdatum DESC, m.BelegID DESC
  OFFSET :off ROWS FETCH NEXT :ps ROWS ONLY;";
$st = $pdo->prepare($sql);
foreach ($par as $k=>$v) $st->bindValue($k, $v);
$st->bindValue(':off', $offset, PDO::PARAM_INT);
$st->bindValue(':ps', $pageSize, PDO::PARAM_INT);
$st->execute();
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$st = $pdo->prepare("SELECT SUM(m.MengeSigned) AS sumSigned FROM dbo.vw_Report_Ausgaben_Mitarbeiter m $whereSql");
$st->execute($par);
$sumSigned = $st->fetch(PDO::FETCH_ASSOC)['sumSigned'] ?? 0;

/* ========= Option-Listen ========= */
$optMitarbeiter = $pdo->query("
  SELECT MitarbeiterID, Vollname
  FROM dbo.vw_Mitarbeiter_Liste
  WHERE Aktiv = 1
  ORDER BY Vollname
")->fetchAll(PDO::FETCH_ASSOC);

$optGruppen = $pdo->query("SELECT MaterialgruppeID, Gruppenname FROM dbo.Materialgruppe ORDER BY Gruppenname")->fetchAll(PDO::FETCH_ASSOC);
$optHersteller = $pdo->query("SELECT HerstellerID, Name FROM dbo.Hersteller ORDER BY Name")->fetchAll(PDO::FETCH_ASSOC);

// Material-Optionen
if ($gruppe && $hersteller) {
  $stm = $pdo->prepare("SELECT m.MaterialID, m.MaterialName
    FROM dbo.Material m
    WHERE m.MaterialgruppeID=:g AND m.HerstellerID=:h
    ORDER BY m.MaterialName");
  $stm->execute([':g'=>$gruppe, ':h'=>$hersteller]);
} elseif ($gruppe) {
  $stm = $pdo->prepare("SELECT m.MaterialID, m.MaterialName
    FROM dbo.Material m WHERE m.MaterialgruppeID=:g ORDER BY m.MaterialName");
  $stm->execute([':g'=>$gruppe]);
} elseif ($hersteller) {
  $stm = $pdo->prepare("SELECT m.MaterialID, m.MaterialName
    FROM dbo.Material m WHERE m.HerstellerID=:h ORDER BY m.MaterialName");
  $stm->execute([':h'=>$hersteller]);
} else {
  $stm = $pdo->query("SELECT MaterialID, MaterialName FROM dbo.Material ORDER BY MaterialName");
}
$optMaterial = $stm->fetchAll(PDO::FETCH_ASSOC);

// Varianten
if ($material) {
  $stv = $pdo->prepare("SELECT VarianteID, VariantenBezeichnung FROM dbo.MatVarianten WHERE MaterialID=:m ORDER BY VariantenBezeichnung");
  $stv->execute([':m'=>$material]);
  $optVariante = $stv->fetchAll(PDO::FETCH_ASSOC);
} else {
  $optVariante = [];
}

// Farben & Größen aus Pivot
if ($material) {
  $stc = $pdo->prepare("
    SELECT DISTINCT p.Farbe
    FROM dbo.vVarAttr_Pivot_Arbeitskleidung p
    JOIN dbo.MatVarianten v ON v.VarianteID = p.VarianteID
    WHERE v.MaterialID = :m AND p.Farbe IS NOT NULL
    ORDER BY p.Farbe");
  $stc->execute([':m'=>$material]);
  $optFarbe = array_map(fn($r)=>$r['Farbe'], $stc->fetchAll(PDO::FETCH_ASSOC));

  $stg = $pdo->prepare("
    SELECT DISTINCT p.Groesse
    FROM dbo.vVarAttr_Pivot_Arbeitskleidung p
    JOIN dbo.MatVarianten v ON v.VarianteID = p.VarianteID
    WHERE v.MaterialID = :m AND p.Groesse IS NOT NULL
    ORDER BY p.Groesse");
  $stg->execute([':m'=>$material]);
  $optGroesse = array_map(fn($r)=>$r['Groesse'], $stg->fetchAll(PDO::FETCH_ASSOC));
} else {
  $optFarbe = [];
  $optGroesse = [];
}

/* ========= URLs für Reset/Export ========= */
$base = rtrim(base_url(), '/');
$qBase = $_GET;
unset($qBase['page']);
$qBase['p'] = 'reporting_mitarbeiter';
$resetUrl = $base.'/?p=reporting_mitarbeiter';
$csvUrl   = $base.'/?'.http_build_query(array_merge($qBase, ['export'=>'csv']));
$pdfUrl   = $base.'/?'.http_build_query(array_merge($qBase, ['export'=>'pdf']));

/* ========= UI ========= */
layout_header('Reporting – Ausgaben pro Mitarbeiter', 'reporting');
?>
  <div class="card">
    <h1>Ausgaben pro Mitarbeiter</h1>

    <form method="get" class="filters">
      <input type="hidden" name="p" value="reporting_mitarbeiter">
      <div>
        <label for="von">Von</label>
        <input type="date" id="von" name="von" value="<?= e($von) ?>">
      </div>
      <div>
        <label for="bis">Bis</label>
        <input type="date" id="bis" name="bis" value="<?= e($bis) ?>">
      </div>
      <div>
        <label for="mitarbeiter">Mitarbeiter</label>
        <select id="mitarbeiter" name="mitarbeiter">
          <option value="">— alle —</option>
          <?php foreach($optMitarbeiter as $o): ?>
            <option value="<?= (int)$o['MitarbeiterID'] ?>" <?= $mitarbeiter===(int)$o['MitarbeiterID']?'selected':'' ?>><?= e($o['Vollname']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="gruppe">Gruppe</label>
        <select id="gruppe" name="gruppe">
          <option value="">— alle —</option>
          <?php foreach($optGruppen as $o): ?>
            <option value="<?= (int)$o['MaterialgruppeID'] ?>" <?= $gruppe===(int)$o['MaterialgruppeID']?'selected':'' ?>><?= e($o['Gruppenname']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="hersteller">Hersteller</label>
        <select id="hersteller" name="hersteller">
          <option value="">— alle —</option>
          <?php foreach($optHersteller as $o): ?>
            <option value="<?= (int)$o['HerstellerID'] ?>" <?= $hersteller===(int)$o['HerstellerID']?'selected':'' ?>><?= e($o['Name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="material">Material</label>
        <select id="material" name="material">
          <option value="">— alle —</option>
          <?php foreach($optMaterial as $o): ?>
            <option value="<?= (int)$o['MaterialID'] ?>" <?= $material===(int)$o['MaterialID']?'selected':'' ?>><?= e($o['MaterialName']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="variante">Variante</label>
        <select id="variante" name="variante">
          <option value="">— alle —</option>
          <?php foreach($optVariante as $o): ?>
            <option value="<?= (int)$o['VarianteID'] ?>" <?= $variante===(int)$o['VarianteID']?'selected':'' ?>><?= e($o['VariantenBezeichnung']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="farbe">Farbe</label>
        <select id="farbe" name="farbe">
          <option value="">— alle —</option>
          <?php foreach($optFarbe as $o): ?>
            <option value="<?= e($o) ?>" <?= $farbe===$o?'selected':'' ?>><?= e($o) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="groesse">Größe</label>
        <select id="groesse" name="groesse">
          <option value="">— alle —</option>
          <?php foreach($optGroesse as $o): ?>
            <option value="<?= e($o) ?>" <?= $groesse===$o?'selected':'' ?>><?= e($o) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="belegnr">BelegNr</label>
        <input type="text" id="belegnr" name="belegnr" value="<?= e($belegnr) ?>">
      </div>
      <div>
        <label for="createdby">Gebucht von</label>
        <input type="text" id="createdby" name="createdby" value="<?= e($createdby) ?>">
      </div>
      <div>
        <label for="bem">Bemerkung</label>
        <input type="text" id="bem" name="bem" value="<?= e($bem) ?>">
      </div>

      <div style="display:flex;gap:8px;align-items:end">
        <button class="btn btn-primary" type="submit">Filtern</button>
        <a class="btn btn-secondary" href="<?= e($resetUrl) ?>">Zurücksetzen</a>
        <a class="btn btn-secondary" href="<?= e($csvUrl) ?>">CSV</a>
        <a class="btn btn-secondary" href="<?= e($pdfUrl) ?>">PDF</a>
      </div>
    </form>

    <div class="hint"><?= (int)$total ?> Treffer • Seite <?= (int)$page ?> von <?= (int)$pages ?> • Σ Menge (±): <strong><?= e(fmt_qty($sumSigned)) ?></strong></div>

    <table>
      <thead>
        <tr>
          <th>Datum</th><th>BelegNr</th><th>Personalnr</th><th>Name</th>
          <th>Material</th><th>Variante</th><th>Farbe</th><th>Größe</th>
          <th style="text-align:right">Menge (±)</th><th>Gebucht von</th><th>Bemerkung</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="11" class="muted">Keine Daten.</td></tr>
        <?php else: foreach ($rows as $r): ?>
          <tr>
            <td><?= e(substr($r['Buchungsdatum'],0,19)) ?></td>
            <td><?= e($r['BelegNr']) ?></td>
            <td><?= e($r['Personalnummer']) ?></td>
            <td><?= e($r['Nachname'].', '.$r['Vorname']) ?></td>
            <td><?= e($r['MaterialName']) ?></td>
            <td><?= e($r['VariantenBezeichnung']) ?></td>
            <td><?= e($r['Farbe']) ?></td>
            <td><?= e($r['Groesse']) ?></td>
            <td style="text-align:right"><?= e(fmt_qty($r['MengeSigned'])) ?></td>
            <td><?= e($r['CreatedByName'] ?: $r['CreatedBy']) ?></td>
            <td><?= e($r['Bemerkung']) ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>

    <?php if ($pages > 1): ?>
      <div class="pagination" style="margin-top:10px;display:flex;gap:8px;align-items:center">
        <?php $qs = function(array $o=[]){ $q = array_merge($_GET,$o); return http_build_query($q); }; ?>
        <a class="btn btn-secondary" href="?<?= $qs(['page'=>1]) ?>" <?= $page==1?'style="opacity:.5;pointer-events:none"':'' ?>>« Erste</a>
        <a class="btn btn-secondary" href="?<?= $qs(['page'=>max(1,$page-1)]) ?>" <?= $page==1?'style="opacity:.5;pointer-events:none"':'' ?>>‹ Zurück</a>
        <span class="muted">Seite <?= (int)$page ?>/<?= (int)$pages ?></span>
        <a class="btn btn-secondary" href="?<?= $qs(['page'=>min($pages,$page+1)]) ?>" <?= $page==$pages?'style="opacity:.5;pointer-events:none"':'' ?>>Weiter ›</a>
        <a class="btn btn-secondary" href="?<?= $qs(['page'=>$pages]) ?>" <?= $page==$pages?'style="opacity:.5;pointer-events:none"':'' ?>>Letzte »</a>
      </div>
    <?php endif; ?>
  </div>
<?php
layout_footer();
