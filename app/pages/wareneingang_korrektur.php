<?php
declare(strict_types=1);

/**
 * Wareneingang Korrektur
 * - Auswahl einer WE-Nummer (BelegNr) – absteigend
 * - Anzeige der Positionen (ausgebelichter WE mit Bewegungsart 101)
 * - Modus "vollständig stornieren" ODER "teilweise" (Checkbox + Menge editierbar)
 * - Bucht Storno-Belege als Bewegungsart 102 mit ReferenzBelegID auf Original
 *
 * Erwartet: $pdo (PDO SQLSRV), Helpers: e(), csrf_token(), csrf_check(), base_url()
 */

$base = rtrim(base_url(), '/');
$errors = [];
$flashSuccess = $_SESSION['flash_success'] ?? null; unset($_SESSION['flash_success']);

/* WE-Nummern (nur solche, die mind. eine 101-Position haben) */
$weNumbers = $pdo->query("
  SELECT DISTINCT bk.BelegNr
  FROM dbo.BelegKopf bk
  WHERE bk.Vorgang='WE'
  ORDER BY bk.BelegNr DESC
")->fetchAll(PDO::FETCH_COLUMN, 0);

$selNr = $_GET['nr'] ?? ($_POST['nr'] ?? '');
$mode  = $_POST['mode'] ?? 'full'; // 'full' oder 'partial'
$stornoDate = $_POST['storno_datum'] ?? date('Y-m-d');

/* Positionen laden */
$rows = [];
if ($selNr !== '') {
  $stmt = $pdo->prepare("
    SELECT mb.BelegID, mb.Position, mb.VarianteID, mb.Menge,
           v.VariantenBezeichnung, m.MaterialName,
           COALESCE(p.Farbe, '') AS Farbe,
           COALESCE(p.Groesse, '') AS Groesse
    FROM dbo.Materialbeleg mb
    JOIN dbo.MatVarianten v ON v.VarianteID = mb.VarianteID
    JOIN dbo.Material m ON m.MaterialID = v.MaterialID
    LEFT JOIN dbo.vVarAttr_Pivot_Arbeitskleidung p ON p.VarianteID = v.VarianteID
    WHERE mb.BelegNr = :nr AND mb.BewegungsartID = 101
    ORDER BY mb.Position
  ");
  $stmt->execute([':nr'=>$selNr]);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* Buchen Storno */
if (($_POST['action'] ?? '') === 'storno') {
  $val = [
    'csrf' => $_POST['csrf'] ?? '',
    'nr'   => $_POST['nr'] ?? '',
    'mode' => $_POST['mode'] ?? 'full',
    'storno_datum' => $_POST['storno_datum'] ?? date('Y-m-d'),
  ];
  if (!csrf_check($val['csrf'])) $errors['_'] = 'Sicherheits-Token ungültig.';
  $d = DateTime::createFromFormat('Y-m-d', $val['storno_datum']);
  if (!$d) $errors['storno_datum'] = 'Ungültiges Datum (YYYY-MM-DD).';

  // Originalpositionen holen
  $origStmt = $pdo->prepare("
    SELECT mb.BelegID, mb.Position, mb.VarianteID, mb.Menge
    FROM dbo.Materialbeleg mb
    WHERE mb.BelegNr = :nr AND mb.BewegungsartID = 101
    ORDER BY mb.Position
  ");
  $origStmt->execute([':nr'=>$val['nr']]);
  $orig = $origStmt->fetchAll(PDO::FETCH_ASSOC);

  if (empty($orig)) $errors['_'] = 'Für diese WE-Nummer wurden keine Positionen gefunden.';

  // Auswahl für Partial
  $sel = $_POST['sel'] ?? [];         // Checkboxen: sel[BelegID] = '1'
  $qty = $_POST['qty'] ?? [];         // Mengen: qty[BelegID] = 'x.y'
  $todo = [];

  if (!$errors) {
    if ($val['mode'] === 'full') {
      foreach ($orig as $r) {
        $todo[] = ['ref'=>$r['BelegID'], 'vid'=>$r['VarianteID'], 'qty'=>(float)$r['Menge']];
      }
    } else {
      $any = false;
      foreach ($orig as $r) {
        $bid = (string)$r['BelegID'];
        if (!isset($sel[$bid])) continue;
        $any = true;
        $q = (float)($qty[$bid] ?? 0);
        if ($q <= 0) { $errors["q_$bid"] = 'Menge > 0 erforderlich.'; continue; }
        if ($q > (float)$r['Menge']) { $errors["q_$bid"] = 'Menge darf Original nicht überschreiten.'; continue; }
        $todo[] = ['ref'=>$r['BelegID'], 'vid'=>$r['VarianteID'], 'qty'=>$q];
      }
      if (!$any) $errors['_'] = 'Bitte mindestens eine Position auswählen.';
    }
  }

  if (!$errors) {
    try {
      $pdo->beginTransaction();

      // Neue BelegNr für Storno erzeugen (eigener Belegkopf)
      $stmtNr = $pdo->prepare("DECLARE @nr NVARCHAR(40); EXEC dbo.sp_NextBelegNr @Vorgang=:v, @BelegNr=@nr OUTPUT; SELECT @nr;");
      $stmtNr->execute([':v'=>'WE']); // Storno WE bleibt im WE-Kreis
      $newNr = (string)$stmtNr->fetchColumn();

      $stmtK = $pdo->prepare("
        INSERT INTO dbo.BelegKopf (BelegNr, Vorgang, BelegDatum, Lieferant, LSNummer, BestellNummer, CreatedAt)
        VALUES (:nr, 'WE', :dt, :lief, :ls, :best, SYSUTCDATETIME())
      ");
      $stmtK->execute([
        ':nr'   => $newNr,
        ':dt'   => $val['storno_datum'],
        ':lief' => 'Storno zu ' . $val['nr'],
        ':ls'   => null,
        ':best' => null,
      ]);

      // Storno-Positionen (102) – ReferenzBelegID auf Original
      $stmtP = $pdo->prepare("
        INSERT INTO dbo.Materialbeleg
          (BelegNr, Position, Buchungsdatum, BewegungsartID, VarianteID, LagerortID, Menge, MitarbeiterID, ReferenzBelegID, Bemerkung, CreatedAt)
        VALUES
          (:nr, :pos, :bd, 102, :vid, NULL, :qty, NULL, :ref, :bem, SYSUTCDATETIME())
      ");
      $pos = 0;
      foreach ($todo as $t) {
        $pos++;
        $stmtP->execute([
          ':nr'  => $newNr,
          ':pos' => $pos,
          ':bd'  => $val['storno_datum'],
          ':vid' => (int)$t['vid'],
          ':qty' => (float)$t['qty'],
          ':ref' => (int)$t['ref'],
          ':bem' => 'Korrektur zu '+$val['nr'],
        ]);
      }

      $pdo->commit();
      $_SESSION['flash_success'] = 'Storno gebucht. Neue WE-Nummer: '.$newNr.' (Referenz: '.$val['nr'].')';
      header('Location: '.$base.'/?p=wareneingang_korrektur&nr='.rawurlencode($val['nr']));
      exit;

    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $errors['_'] = 'Storno fehlgeschlagen: '.$e->getMessage();
    }
  }
}
?>
<div class="card">
  <h1>Wareneingang – Korrektur</h1>

  <?php if ($flashSuccess): ?>
    <div class="alert alert-success"><?= e($flashSuccess) ?></div>
  <?php endif; ?>
  <?php if (!empty($errors['_'])): ?>
    <div class="alert alert-error"><?= e($errors['_']) ?></div>
  <?php endif; ?>

  <div class="tabs" style="margin-bottom:12px;display:flex;gap:8px;flex-wrap:wrap">
    <a class="btn btn-secondary" href="<?= e($base) ?>/?p=wareneingang">Wareneingang</a>
    <a class="btn btn-secondary" href="<?= e($base) ?>/?p=wareneingang_korrektur">WE Korrektur</a>
    <span class="btn" style="pointer-events:none;opacity:.6">Warenausgang (folgt)</span>
    <span class="btn" style="pointer-events:none;opacity:.6">Inventur (folgt)</span>
  </div>

  <form method="get" style="margin-bottom:10px">
    <input type="hidden" name="p" value="wareneingang_korrektur">
    <div class="grid2">
      <div>
        <label for="nr">WE-Nummer</label>
        <select id="nr" name="nr">
          <option value="">— bitte wählen —</option>
          <?php foreach ($weNumbers as $nr): ?>
            <option value="<?= e($nr) ?>" <?= ($selNr===$nr?'selected':'') ?>><?= e($nr) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="storno_datum">Buchungsdatum Storno</label>
        <input type="date" id="storno_datum" name="storno_datum" value="<?= e($stornoDate) ?>">
      </div>
    </div>
    <button class="btn btn-primary" type="submit">Laden</button>
  </form>

  <?php if ($selNr !== '' && empty($rows)): ?>
    <div class="alert alert-error">Zu dieser WE-Nummer wurden keine Positionen gefunden.</div>
  <?php endif; ?>

  <?php if (!empty($rows)): ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="storno">
      <input type="hidden" name="nr" value="<?= e($selNr) ?>">
      <input type="hidden" name="storno_datum" value="<?= e($stornoDate) ?>">

      <div style="display:flex;gap:16px;align-items:center;margin:.5rem 0">
        <label style="display:flex;gap:.4rem;align-items:center">
          <input type="radio" name="mode" value="full" <?= ($mode==='partial'?'':'checked') ?> onchange="togglePartial(false)"> vollständig stornieren
        </label>
        <label style="display:flex;gap:.4rem;align-items:center">
          <input type="radio" name="mode" value="partial" <?= ($mode==='partial'?'checked':'') ?> onchange="togglePartial(true)"> teilweise stornieren
        </label>
      </div>

      <table id="korTable">
        <thead>
          <tr>
            <th style="width:40px"></th>
            <th style="width:70px">Pos.</th>
            <th>Artikel / Variante</th>
            <th>Farbe</th>
            <th>Größe</th>
            <th style="width:120px">Menge</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): $bid=(int)$r['BelegID']; ?>
            <tr>
              <td>
                <input type="checkbox" name="sel[<?= $bid ?>]" value="1" <?= ($mode==='partial'?'':'disabled') ?>>
              </td>
              <td><?= (int)$r['Position'] ?></td>
              <td>
                <div><strong><?= e($r['MaterialName']) ?></strong></div>
                <div class="muted"><?= e($r['VariantenBezeichnung']) ?></div>
              </td>
              <td><?= e($r['Farbe']) ?></td>
              <td><?= e($r['Groesse']) ?></td>
              <td>
                <input type="number" name="qty[<?= $bid ?>]" step="0.001" min="0.001"
                       value="<?= e($r['Menge']) ?>"
                       <?= ($mode==='partial'?'':'disabled') ?>>
                <?php if (!empty($errors["q_$bid"])): ?>
                  <div class="alert alert-error"><?= e($errors["q_$bid"]) ?></div>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <div style="margin-top:.6rem;display:flex;gap:8px">
        <button class="btn btn-primary" type="submit">Storno buchen</button>
        <a class="btn btn-secondary" href="<?= e($base) ?>/?p=wareneingang_korrektur&nr=<?= e(rawurlencode($selNr)) ?>">Zurücksetzen</a>
      </div>
    </form>
  <?php endif; ?>
</div>

<script>
function togglePartial(enable){
  const form = document.querySelector('#korTable').closest('form');
  form.querySelectorAll('input[type=checkbox][name^="sel["]').forEach(cb=>{
    cb.disabled = !enable;
    if (!enable) cb.checked = false;
  });
  form.querySelectorAll('input[type=number][name^="qty["]').forEach(inp=>{
    inp.disabled = !enable;
  });
}
document.addEventListener('DOMContentLoaded', function(){
  const modePartial = document.querySelector('input[name="mode"][value="partial"]');
  togglePartial(modePartial?.checked);
});
</script>
