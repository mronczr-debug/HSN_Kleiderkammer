<?php
declare(strict_types=1);

/**
 * Varianten anlegen für ein Material
 * - Verwendet INSERT ... OUTPUT INSERTED.VarianteID (SQLSRV-sicher)
 * - Integriert in globales Layout (Router rendert header/footer)
 *
 * Aufruf: ?p=variant_create&material_id=...
 *
 * Erwartet: $pdo (PDO SQLSRV), Helpers e(), csrf_token(), csrf_check(), base_url()
 */

$base = rtrim(base_url(), '/');

/* ====== Input: material_id ====== */
$materialId = isset($_GET['material_id']) ? (int)$_GET['material_id'] : 0;
if ($materialId <= 0) {
  http_response_code(400);
  echo '<div class="card"><h1>Fehler</h1><p class="muted">material_id fehlt.</p></div>';
  return;
}

/* ====== Lookup: Material, Gruppe ====== */
$sqlMat = "SELECT m.MaterialID, m.MaterialName, m.Beschreibung, m.BasisSKU, m.IsActive,
                  mg.MaterialgruppeID, mg.Gruppenname
           FROM dbo.Material m
           JOIN dbo.Materialgruppe mg ON mg.MaterialgruppeID = m.MaterialgruppeID
           WHERE m.MaterialID = :id";
$st = $pdo->prepare($sqlMat); $st->execute([':id'=>$materialId]); $material = $st->fetch(PDO::FETCH_ASSOC);
if (!$material) {
  http_response_code(404);
  echo '<div class="card"><h1>Fehler</h1><p class="muted">Material nicht gefunden.</p></div>';
  return;
}

/* ====== Merkmal-IDs (Farbe, Größe) ====== */
$attrStmt = $pdo->prepare("SELECT MerkmalID, MerkmalName FROM dbo.MatAttribute WHERE MerkmalName IN (N'Farbe', N'Größe')");
$attrStmt->execute();
$attrMap = [];
while ($r = $attrStmt->fetch(PDO::FETCH_ASSOC)) { $attrMap[$r['MerkmalName']] = (int)$r['MerkmalID']; }
$merkmalIdFarbe   = $attrMap['Farbe']  ?? null;
$merkmalIdGroesse = $attrMap['Größe']  ?? null;

/* ====== Allowed Values: Farbe ====== */
$allowedColors = [];
if ($merkmalIdFarbe) {
  $st = $pdo->prepare("SELECT AllowedValue FROM dbo.MatAttributeAllowedValues WHERE MerkmalID = :id ORDER BY SortOrder, AllowedValue");
  $st->execute([':id'=>$merkmalIdFarbe]);
  $allowedColors = $st->fetchAll(PDO::FETCH_COLUMN, 0);
}

/* ====== Allowed Values: Größe je Gruppe (View) ====== */
$st = $pdo->prepare("SELECT Groesse FROM dbo.vAllowed_Groessen_ByGruppe WHERE MaterialgruppeID = :gid ORDER BY SortOrder, Groesse");
$st->execute([':gid'=>$material['MaterialgruppeID']]);
$allowedSizes = $st->fetchAll(PDO::FETCH_COLUMN, 0);
$groupHasSizeProfile = count($allowedSizes) > 0;

/* ====== Weitere Attribute (ohne Farbe/Größe) ====== */
$attrAll = $pdo->query("
  SELECT a.MerkmalID, a.MerkmalName, a.Datentyp,
         (SELECT STRING_AGG(av.AllowedValue, '||') WITHIN GROUP (ORDER BY av.SortOrder, av.AllowedValue)
            FROM dbo.MatAttributeAllowedValues av WHERE av.MerkmalID = a.MerkmalID) AS AllowedValuesConcat
  FROM dbo.MatAttribute a
  WHERE a.MerkmalName NOT IN (N'Farbe', N'Größe')
  ORDER BY a.MerkmalName
")->fetchAll(PDO::FETCH_ASSOC);

/* ====== Form-Values ====== */
$values = [
  'VarianteName' => $_POST['VarianteName'] ?? '',
  'SKU'          => $_POST['SKU'] ?? '',
  'Barcode'      => $_POST['Barcode'] ?? '',
  'IsActive'     => isset($_POST['IsActive']) ? '1' : '0',
  'Farbe'        => $_POST['Farbe'] ?? '',
  'Groesse'      => $_POST['Groesse'] ?? '',
  'csrf'         => $_POST['csrf'] ?? '',
];
$otherAttrValues = [];
foreach ($attrAll as $a) {
  $key = 'attr_' . (int)$a['MerkmalID'];
  if (isset($_POST[$key])) {
    $otherAttrValues[(int)$a['MerkmalID']] = trim((string)$_POST[$key]);
  }
}

$errors = [];

/* ====== Handle POST ====== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check($values['csrf'])) { $errors['csrf'] = 'Sicherheits-Token ungültig. Bitte Seite neu laden.'; }
  if (trim($values['VarianteName']) === '') { $errors['VarianteName'] = 'Bitte Variantenbezeichnung angeben.'; }
  if (mb_strlen($values['VarianteName']) > 200) { $errors['VarianteName'] = 'Max. 200 Zeichen.'; }
  if ($values['SKU'] !== '' && mb_strlen($values['SKU']) > 100) { $errors['SKU'] = 'SKU max. 100 Zeichen.'; }
  if ($values['Barcode'] !== '' && mb_strlen($values['Barcode']) > 100) { $errors['Barcode'] = 'Barcode max. 100 Zeichen.'; }

  // Farbe validieren (wenn Liste gepflegt)
  if ($merkmalIdFarbe && $values['Farbe'] !== '' && !in_array($values['Farbe'], $allowedColors, true)) {
    $errors['Farbe'] = 'Ungültige Farbe.';
  }
  // Größe validieren (nur wenn Gruppe Profil hat)
  if ($groupHasSizeProfile) {
    if ($values['Groesse'] === '') {
      $errors['Groesse'] = 'Bitte Größe wählen.';
    } elseif (!in_array($values['Groesse'], $allowedSizes, true)) {
      $errors['Groesse'] = 'Ungültige Größe.';
    }
  }
  // Weitere Attribute validieren
  foreach ($attrAll as $a) {
    $mid = (int)$a['MerkmalID']; $k = 'attr_'.$mid;
    if (!array_key_exists($mid, $otherAttrValues)) continue;
    $val = $otherAttrValues[$mid];
    if ($val === '') continue;
    if ($a['AllowedValuesConcat']) {
      $opts = explode('||', (string)$a['AllowedValuesConcat']);
      if (!in_array($val, $opts, true)) $errors[$k] = 'Ungültiger Wert für „'.$a['MerkmalName'].'“.';
    } else {
      if ($a['Datentyp'] === 'INT'     && !is_numeric($val))                    $errors[$k] = 'Zahl erwartet.';
      if ($a['Datentyp'] === 'DECIMAL' && !is_numeric($val))                    $errors[$k] = 'Dezimalzahl erwartet.';
      if ($a['Datentyp'] === 'BOOL'    && !in_array(strtolower($val), ['0','1','false','true'], true)) $errors[$k] = 'Bool (0/1) erwartet.';
      if ($a['Datentyp'] === 'DATE'    && (date_create($val) === false))        $errors[$k] = 'Datum erwartet (YYYY-MM-DD).';
    }
  }

  if (!$errors) {
    try {
      $pdo->beginTransaction();

      // SQLSRV-stabil: OUTPUT INSERTED.VarianteID
      $sqlVar = "
        INSERT INTO dbo.MatVarianten (MaterialID, VariantenBezeichnung, SKU, Barcode, IsActive, CreatedAt)
        OUTPUT INSERTED.VarianteID
        VALUES (:mid, :name, :sku, :barcode, :act, SYSUTCDATETIME())
      ";
      $stIns = $pdo->prepare($sqlVar);
      $stIns->execute([
        ':mid'     => $materialId,
        ':name'    => $values['VarianteName'],
        ':sku'     => ($values['SKU'] === '' ? null : $values['SKU']),
        ':barcode' => ($values['Barcode'] === '' ? null : $values['Barcode']),
        ':act'     => ($values['IsActive'] === '1' ? 1 : 0),
      ]);
      $varianteId = (int)$stIns->fetchColumn();

      // Attribute schreiben
      $insAttr = $pdo->prepare("
        INSERT INTO dbo.MatVariantenAttribute (VarianteID, MerkmalID, MerkmalWert)
        VALUES (:vid, :mid, :val)
      ");

      if ($merkmalIdFarbe && $values['Farbe'] !== '') {
        $insAttr->execute([':vid'=>$varianteId, ':mid'=>$merkmalIdFarbe,  ':val'=>$values['Farbe']]);
      }
      if ($merkmalIdGroesse && $groupHasSizeProfile && $values['Groesse'] !== '') {
        $insAttr->execute([':vid'=>$varianteId, ':mid'=>$merkmalIdGroesse, ':val'=>$values['Groesse']]);
      }
      foreach ($otherAttrValues as $mid => $val) {
        if ($val === '') continue;
        $insAttr->execute([':vid'=>$varianteId, ':mid'=>$mid, ':val'=>$val]);
      }

      $pdo->commit();
      $_SESSION['flash_success'] = 'Variante wurde angelegt.';
      header('Location: '.$base.'/?p=variant_create&material_id='.$materialId);
      exit;

    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $errors['_'] = 'Speichern fehlgeschlagen: '.$e->getMessage();
    }
  }
}

/* ====== Bestehende Varianten laden ====== */
$existing = $pdo->prepare("
  SELECT v.VarianteID, v.VariantenBezeichnung, v.SKU, v.Barcode, v.IsActive,
         MAX(CASE WHEN a.MerkmalName = N'Farbe' THEN va.MerkmalWert END) AS Farbe,
         MAX(CASE WHEN a.MerkmalName = N'Größe' THEN va.MerkmalWert END) AS Groesse
  FROM dbo.MatVarianten v
  LEFT JOIN dbo.MatVariantenAttribute va ON va.VarianteID = v.VarianteID
  LEFT JOIN dbo.MatAttribute a ON a.MerkmalID = va.MerkmalID
  WHERE v.MaterialID = :mid
  GROUP BY v.VarianteID, v.VariantenBezeichnung, v.SKU, v.Barcode, v.IsActive
  ORDER BY v.VarianteID DESC
");
$existing->execute([':mid'=>$materialId]);
$variants = $existing->fetchAll(PDO::FETCH_ASSOC);

/* ========= UI ========= */
$flashSuccess = $_SESSION['flash_success'] ?? null; unset($_SESSION['flash_success']);
?>
<div class="split">
  <div class="card">
    <h1>Variante anlegen</h1>
    <div class="subtitle">
      <strong>Material:</strong> <?= e($material['MaterialName']) ?>
      <span class="pill" style="border-color:#d1d5db;background:#eef2ff;color:#1f2937;"><?= e($material['Gruppenname']) ?></span>
    </div>

    <?php if ($flashSuccess): ?>
      <div class="alert alert-success"><?= e($flashSuccess) ?></div>
    <?php endif; ?>
    <?php if (!empty($errors['_'])): ?>
      <div class="alert alert-error"><?= e($errors['_']) ?></div>
    <?php endif; ?>

    <form method="post" action="">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

      <div class="row">
        <div>
          <label for="VarianteName">Variantenbezeichnung *</label>
          <input type="text" id="VarianteName" name="VarianteName" maxlength="200" required value="<?= e($values['VarianteName']) ?>">
          <?php if (!empty($errors['VarianteName'])): ?><div class="alert alert-error"><?= e($errors['VarianteName']) ?></div><?php endif; ?>
          <div class="hint">z. B. „Schwarz, 52“ oder „Rot, XL“</div>
        </div>
        <div>
          <label for="SKU">SKU / Artikelnummer</label>
          <input type="text" id="SKU" name="SKU" maxlength="100" value="<?= e($values['SKU']) ?>">
          <?php if (!empty($errors['SKU'])): ?><div class="alert alert-error"><?= e($errors['SKU']) ?></div><?php endif; ?>
        </div>
      </div>

      <div class="row">
        <div>
          <label for="Barcode">Barcode</label>
          <input type="text" id="Barcode" name="Barcode" maxlength="100" value="<?= e($values['Barcode']) ?>">
          <?php if (!empty($errors['Barcode'])): ?><div class="alert alert-error"><?= e($errors['Barcode']) ?></div><?php endif; ?>
        </div>
        <div style="display:flex;align-items:flex-end">
          <label style="display:flex;gap:.5rem;align-items:center;margin:0">
            <input type="checkbox" name="IsActive" value="1" <?= ($values['IsActive']==='1'?'checked':'') ?>> Aktiv
          </label>
        </div>
      </div>

      <div class="row">
        <div>
          <label for="Farbe">Farbe</label>
          <select id="Farbe" name="Farbe">
            <option value="">— bitte wählen —</option>
            <?php foreach ($allowedColors as $c): ?>
              <option value="<?= e($c) ?>" <?= ($values['Farbe'] === $c ? 'selected' : '') ?>><?= e($c) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if (!empty($errors['Farbe'])): ?><div class="alert alert-error"><?= e($errors['Farbe']) ?></div><?php endif; ?>
        </div>

        <div>
          <label for="Groesse">Größe<?= $groupHasSizeProfile ? ' *' : '' ?></label>
          <?php if ($groupHasSizeProfile): ?>
            <select id="Groesse" name="Groesse" required>
              <option value="">— bitte wählen —</option>
              <?php foreach ($allowedSizes as $s): ?>
                <option value="<?= e($s) ?>" <?= ($values['Groesse'] === $s ? 'selected' : '') ?>><?= e($s) ?></option>
              <?php endforeach; ?>
            </select>
          <?php else: ?>
            <input type="text" id="Groesse" name="Groesse" value="<?= e($values['Groesse']) ?>" placeholder="(optional)">
          <?php endif; ?>
          <?php if (!empty($errors['Groesse'])): ?><div class="alert alert-error"><?= e($errors['Groesse']) ?></div><?php endif; ?>
          <div class="hint">Größen kommen aus dem Profil der Gruppe „<?= e($material['Gruppenname']) ?>“.</div>
        </div>
      </div>

      <?php if (!empty($attrAll)): ?>
        <div class="row-3">
          <?php foreach ($attrAll as $a):
            $mid = (int)$a['MerkmalID']; $k='attr_'.$mid; $val = $otherAttrValues[$mid] ?? '';
            $opts = $a['AllowedValuesConcat'] ? explode('||', (string)$a['AllowedValuesConcat']) : [];
          ?>
            <div>
              <label for="<?= e($k) ?>"><?= e($a['MerkmalName']) ?></label>
              <?php if (!empty($opts)): ?>
                <select id="<?= e($k) ?>" name="<?= e($k) ?>">
                  <option value="">— bitte wählen —</option>
                  <?php foreach ($opts as $o): ?>
                    <option value="<?= e($o) ?>" <?= ($val===$o?'selected':'') ?>><?= e($o) ?></option>
                  <?php endforeach; ?>
                </select>
              <?php else: ?>
                <input type="text" id="<?= e($k) ?>" name="<?= e($k) ?>" value="<?= e($val) ?>">
              <?php endif; ?>
              <?php if (!empty($errors[$k])): ?><div class="alert alert-error"><?= e($errors[$k]) ?></div><?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div style="display:flex;gap:8px;align-items:center;margin-top:.5rem">
        <button class="btn btn-primary" type="submit">Variante speichern</button>
        <a class="btn btn-secondary" href="<?= e($base) ?>/?p=materials">Zur Materialliste</a>
      </div>
    </form>

    <h2 style="margin-top:2rem;">Vorhandene Varianten</h2>
    <?php if (empty($variants)): ?>
      <p class="hint">Noch keine Varianten vorhanden.</p>
    <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>ID</th><th>Bezeichnung</th><th>Farbe</th><th>Größe</th><th>SKU</th><th>Barcode</th><th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($variants as $v): ?>
            <tr>
              <td><?= (int)$v['VarianteID'] ?></td>
              <td><?= e($v['VariantenBezeichnung']) ?></td>
              <td><?= e($v['Farbe'] ?? '') ?></td>
              <td><?= e($v['Groesse'] ?? '') ?></td>
              <td><?= e($v['SKU'] ?? '') ?></td>
              <td><?= e($v['Barcode'] ?? '') ?></td>
              <td><?= ((int)$v['IsActive']===1 ? '<span class="pill on">aktiv</span>' : '<span class="pill off">inaktiv</span>') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <!-- Rechte Info/Tipps -->
  <div class="card">
    <h2>Hinweise</h2>
    <ul class="muted" style="margin-left:1rem">
      <li>Farbe/Größe sind Attribute; weitere Merkmale kannst du frei pflegen.</li>
      <li>Größenprofil kommt aus der Materialgruppe „<?= e($material['Gruppenname']) ?>“.</li>
      <li>Du kannst später Attribute ergänzen/ändern; die Liste unten zeigt vorhandene Varianten.</li>
    </ul>
    <div style="margin-top:1rem">
      <a class="btn btn-secondary" href="<?= e($base) ?>/?p=materials">Zur Materialliste</a>
    </div>
  </div>
</div>
