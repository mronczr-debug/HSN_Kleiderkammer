<?php
// app/pages/ausgabe_lieferung.php
declare(strict_types=1);

layout_header('Lieferung erstellen / buchen', 'ausgabe');

global $pdo;

/** ───── Helfer ───── */
$fmtQty = function($q): string {
  $s = number_format((float)$q, 3, '.', '');
  $s = rtrim(rtrim($s, '0'), '.');
  return $s === '' ? '0' : $s;
};
$consumeToCols = function(PDOStatement $st): void {
  // Weiterblättern, bis ein Resultset mit Spalten anliegt (oder nichts mehr kommt).
  while ($st->columnCount() === 0) {
    if (!$st->nextRowset()) break;
  }
};
function get_user_login(): ?string {
  return $_SERVER['AUTH_USER'] ?? $_SERVER['REMOTE_USER'] ?? null;
}

/** ───── Eingabe ───── */
$bestellungId = isset($_GET['bestellung_id']) ? (int)$_GET['bestellung_id'] : 0;
$lieferungId  = isset($_GET['lieferung_id'])  ? (int)$_GET['lieferung_id']  : 0;

/** ───── Lieferung aus Bestellung erzeugen ───── */
if ($lieferungId <= 0 && $bestellungId > 0) {
  try {
    // Bestellung laden (nur offen)
    $st = $pdo->prepare("
      SELECT k.BestellungID, k.BestellNr, k.Typ, k.MitarbeiterID, k.Status, k.RueckgabeGrund,
             ms.Nachname, ms.Vorname
      FROM dbo.MitarbeiterBestellungKopf k
      JOIN dbo.MitarbeiterStamm ms ON ms.MitarbeiterID = k.MitarbeiterID
      WHERE k.BestellungID = :bid AND k.Status = 0
    ");
    $st->execute([':bid'=>$bestellungId]);
    $best = $st->fetch(PDO::FETCH_ASSOC);
    if (!$best) {
      echo '<div class="card"><div class="alert alert-error">Bestellung nicht gefunden oder nicht offen.</div></div>';
      layout_footer(); return;
    }

    // Schon offene Lieferung vorhanden?
    $st = $pdo->prepare("SELECT LieferungID FROM dbo.MitarbeiterLieferungKopf WHERE BestellungID = :bid AND Status = 0");
    $st->execute([':bid'=>$bestellungId]);
    $exists = $st->fetchColumn();
    if ($exists) {
      header('Location: ?p=ausgabe_lieferung&lieferung_id='.(int)$exists);
      exit;
    }

    // Neue LieferNr via SP holen (Resultsets sauber konsumieren)
    $vorgang = ($best['Typ'] === 'R') ? 'RUE' : 'AUS';
    $stmtNr = $pdo->prepare("DECLARE @nr NVARCHAR(40); EXEC dbo.sp_NextBelegNr :vorg, @nr OUTPUT; SELECT @nr AS Nr;");
    $stmtNr->execute([':vorg'=>$vorgang]);
    // Falls der Treiber zuerst Rowcount liefert, zur Select-Row blättern:
    while ($stmtNr->columnCount() === 0 && $stmtNr->nextRowset()) { /* skip */ }
    $lieferNr = (string)$stmtNr->fetchColumn();

    $pdo->beginTransaction();

    // LieferungKopf anlegen + ID holen (ebenfalls Rowcount wegklicken)
    $stmtK = $pdo->prepare("
      INSERT INTO dbo.MitarbeiterLieferungKopf
        (LieferNr, Typ, BestellungID, MitarbeiterID, LieferDatum, Status, RueckgabeGrund, PdfPath, CreatedAt, CreatedBy)
      VALUES
        (:lnr, :typ, :bid, :mid, SYSUTCDATETIME(), 0, :rg, NULL, SYSUTCDATETIME(), :cb);
      SELECT CAST(SCOPE_IDENTITY() AS BIGINT) AS NewID;
    ");
    $stmtK->execute([
      ':lnr' => $lieferNr,
      ':typ' => $best['Typ'],
      ':bid' => (int)$best['BestellungID'],
      ':mid' => (int)$best['MitarbeiterID'],
      ':rg'  => ($best['Typ'] === 'R' ? ($best['RueckgabeGrund'] ?? '') : null),
      ':cb'  => get_user_login(),
    ]);
    while ($stmtK->columnCount() === 0 && $stmtK->nextRowset()) { /* skip */ }
    $row = $stmtK->fetch(PDO::FETCH_ASSOC);
    if (!$row || !isset($row['NewID'])) {
      throw new RuntimeException('Konnte neue LieferungID nicht ermitteln.');
    }
    $lieferungId = (int)$row['NewID'];

    // Positionen aus Bestellung übernehmen
    $stmtP = $pdo->prepare("
      INSERT INTO dbo.MitarbeiterLieferungPos (LieferungID, PosNr, VarianteID, Menge)
      SELECT :lid, p.PosNr, p.VarianteID, p.Menge
      FROM dbo.MitarbeiterBestellungPos p
      WHERE p.BestellungID = :bid
    ");
    $stmtP->execute([':lid'=>$lieferungId, ':bid'=>$bestellungId]);

    $pdo->commit();

    header('Location: ?p=ausgabe_lieferung&lieferung_id='.$lieferungId);
    exit;

  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo '<div class="card"><div class="alert alert-error">Erzeugen der Lieferung fehlgeschlagen: '.e($e->getMessage()).'</div></div>';
    layout_footer(); return;
  }
}

/** ───── Ohne LieferungID geht es nicht weiter ───── */
if ($lieferungId <= 0) {
  echo '<div class="card"><div class="alert alert-error">Parameter fehlt. Rufe diese Seite mit <code>?bestellung_id=…</code> oder <code>?lieferung_id=…</code> auf.</div></div>';
  layout_footer(); return;
}

/** ───── Lieferung laden ───── */
$st = $pdo->prepare("
  SELECT l.*, k.BestellNr, k.Typ AS BestellTyp,
         ms.Nachname, ms.Vorname, ms.Personalnummer
  FROM dbo.MitarbeiterLieferungKopf l
  JOIN dbo.MitarbeiterBestellungKopf k ON k.BestellungID = l.BestellungID
  JOIN dbo.MitarbeiterStamm ms ON ms.MitarbeiterID = l.MitarbeiterID
  WHERE l.LieferungID = :lid
");
$st->execute([':lid'=>$lieferungId]);
$kopf = $st->fetch(PDO::FETCH_ASSOC);
if (!$kopf) {
  echo '<div class="card"><div class="alert alert-error">Lieferung nicht gefunden.</div></div>';
  layout_footer(); return;
}

/** ───── Aktionen ───── */
$errors = [];

if (($_POST['action'] ?? '') === 'discard') {
  if ((int)$kopf['Status'] !== 0) {
    $errors[] = 'Nur offene Lieferungen können verworfen werden.';
  } else {
    try {
      $pdo->beginTransaction();

      $delP = $pdo->prepare("DELETE FROM dbo.MitarbeiterLieferungPos WHERE LieferungID = :lid");
      $delP->execute([':lid'=>$lieferungId]);

      $updB = $pdo->prepare("UPDATE dbo.MitarbeiterBestellungKopf SET Status = 0 WHERE BestellungID = :bid");
      $updB->execute([':bid'=>$kopf['BestellungID']]);

      $delK = $pdo->prepare("DELETE FROM dbo.MitarbeiterLieferungKopf WHERE LieferungID = :lid");
      $delK->execute([':lid'=>$lieferungId]);

      $pdo->commit();
      flash('success', 'Lieferung verworfen.');
      header('Location: ?p=ausgabe');
      exit;
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $errors[] = 'Verwerfen fehlgeschlagen: '.$e->getMessage();
    }
  }
}

if (($_POST['action'] ?? '') === 'book') {
  if ($kopf['Typ'] !== 'A') {
    $errors[] = 'Buchen ist aktuell nur für Ausgaben (Typ A) implementiert.';
  } elseif ((int)$kopf['Status'] === 1) {
    $errors[] = 'Diese Lieferung ist bereits gebucht.';
  } else {
    try {
      $stmt = $pdo->prepare("EXEC dbo.sp_Ausgabe_Buchen :lid");
      $stmt->bindValue(':lid', $lieferungId, PDO::PARAM_INT);
      $stmt->execute();
      // Alle evtl. leeren Rowsets schließen:
      while ($stmt->nextRowset()) { /* noop */ }

      flash('success', 'Lieferung wurde erfolgreich gebucht.');
      header('Location: ?p=ausgabe');
      exit;
    } catch (Throwable $e) {
      $errors[] = 'Buchen fehlgeschlagen: '.$e->getMessage();
    }
  }
}

/** ───── Positionen lesen (Anzeige) ───── */
$pos = $pdo->prepare("
  SELECT p.PosNr, p.VarianteID, p.Menge,
         vf.MaterialName, vf.VariantenBezeichnung, vf.Farbe, vf.Groesse, vf.SKU
  FROM dbo.MitarbeiterLieferungPos p
  JOIN dbo.vVarianten_Filter_FarbeGroesse vf ON vf.VarianteID = p.VarianteID
  WHERE p.LieferungID = :lid
  ORDER BY p.PosNr
");
$pos->execute([':lid'=>$lieferungId]);
$positionen = $pos->fetchAll(PDO::FETCH_ASSOC);

/** ───── UI ───── */
?>
<div class="card">
  <h1>Lieferung <?= e($kopf['LieferNr'] ?? '') ?> <?= (int)$kopf['Status']===1 ? '<span class="pill on">gebucht</span>' : '' ?></h1>
  <div class="hint">
    <strong>Bestellung:</strong> <?= e($kopf['BestellNr'] ?? '') ?> •
    <strong>Mitarbeiter:</strong> <?= e(($kopf['Nachname'] ?? '').', '.($kopf['Vorname'] ?? '')) ?> (<?= e($kopf['Personalnummer'] ?? '') ?>) •
    <strong>Typ:</strong> <?= ($kopf['Typ']==='R'?'Rückgabe':'Ausgabe') ?> •
    <strong>Datum:</strong> <?= e((string)$kopf['LieferDatum']) ?>
    <?php if ($kopf['Typ']==='R' && !empty($kopf['RueckgabeGrund'])): ?>
      • <strong>Grund:</strong> <?= e($kopf['RueckgabeGrund']) ?>
    <?php endif; ?>
  </div>

  <?php foreach ($errors as $err): ?>
    <div class="alert alert-error"><?= e($err) ?></div>
  <?php endforeach; ?>
  <?php if ($msg = flash('success')): ?>
    <div class="alert alert-success"><?= e($msg) ?></div>
  <?php endif; ?>

  <h2 style="margin-top:.8rem;">Positionen</h2>
  <?php if (empty($positionen)): ?>
    <div class="hint">Keine Positionen vorhanden.</div>
  <?php else: ?>
    <table>
      <thead>
        <tr>
          <th style="width:70px">Pos.</th>
          <th>Artikel</th>
          <th>Variante</th>
          <th>Farbe</th>
          <th>Größe</th>
          <th>SKU</th>
          <th style="width:120px; text-align:right">Menge</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($positionen as $p): ?>
          <tr>
            <td><?= (int)$p['PosNr'] ?></td>
            <td><?= e($p['MaterialName'] ?? '') ?></td>
            <td><?= e($p['VariantenBezeichnung'] ?? '') ?></td>
            <td><?= e($p['Farbe'] ?? '') ?></td>
            <td><?= e($p['Groesse'] ?? '') ?></td>
            <td class="muted"><?= e($p['SKU'] ?? '') ?></td>
            <td style="text-align:right"><?= e($fmtQty($p['Menge'])) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <form method="post" class="actions" style="margin-top:14px; display:flex; gap:8px; align-items:center">
    <?php if ((int)$kopf['Status'] === 0): ?>
      <button class="btn btn-primary" type="submit" name="action" value="book">Ausgabe buchen</button>
      <button class="btn btn-secondary" type="submit" name="action" value="discard"
              onclick="return confirm('Diese Lieferung verwerfen?')">Verwerfen</button>
    <?php else: ?>
      <span class="hint">Lieferung ist bereits gebucht.</span>
    <?php endif; ?>
    <a class="btn btn-secondary" href="?p=ausgabe">Zur Übersicht</a>
  </form>
</div>

<?php
layout_footer();
