<?php
declare(strict_types=1);

/**
 * Varianten anlegen für ein Material
 * - Variantenbezeichnung automatisch aus Farbe/Größe („Farbe, Größe“)
 * - SKU automatisch aus BasisSKU/Materialname + Farbcode + Größe, mit Eindeutigkeits-Suffix -2/-3/...
 * - Gruppe steuert zulässige Größen (vAllowed_Groessen_ByGruppe)
 * - Erkennt sowohl Merkmal "Größe" als auch "Groesse"
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

/* ====== Merkmal-IDs (Farbe, Größe/Groesse) ====== */
$attrStmt = $pdo->prepare("
  SELECT MerkmalID, MerkmalName
  FROM dbo.MatAttribute
  WHERE MerkmalName IN (N'Farbe', N'Größe', N'Groesse')
");
$attrStmt->execute();
$attrMap = [];
$merkmalIdFarbe = null;
$merkmalIdGroesse = null;
while ($r = $attrStmt->fetch(PDO::FETCH_ASSOC)) {
  $name = (string)$r['MerkmalName'];
  if ($name === 'Farbe') {
    $merkmalIdFarbe = (int)$r['MerkmalID'];
  } elseif ($name === 'Größe' || $name === 'Groesse') {
    $merkmalIdGroesse = (int)$r['MerkmalID'];
  }
}

/* ====== Allowed Values: Farbe ====== */
$allowedColors = [];
if ($merkmalIdFarbe) {
  $st = $pdo->prepare("
    SELECT AllowedValue
    FROM dbo.MatAttributeAllowedValues
    WHERE MerkmalID = :id
    ORDER BY SortOrder, AllowedValue
  ");
  $st->execute([':id'=>$merkmalIdFarbe]);
  $allowedColors = $st->fetchAll(PDO::FETCH_COLUMN, 0);
}

/* ====== Allowed Values: Größe je Gruppe (View) ====== */
$st = $pdo->prepare("
  SELECT Groesse
  FROM dbo.vAllowed_Groessen_ByGruppe
  WHERE MaterialgruppeID = :gid
  ORDER BY SortOrder, Groesse
");
$st->execute([':gid'=>$material['MaterialgruppeID']]);
$allowedSizes = $st->fetchAll(PDO::FETCH_COLUMN, 0);
$groupHasSizeProfile = count($allowedSizes) > 0;

/* ====== Weitere Attribute (ohne Farbe/Größe) ====== */
$attrAll = $pdo->query("
  SELECT a.MerkmalID, a.MerkmalName, a.Datentyp,
         (SELECT STRING_AGG(av.AllowedValue, '||') WITHIN GROUP (ORDER BY av.SortOrder, av.AllowedValue)
            FROM dbo.MatAttributeAllowedValues av WHERE av.MerkmalID = a.MerkmalID) AS AllowedValuesConcat
  FROM dbo.MatAttribute a
  WHERE a.MerkmalName NOT IN (N'Farbe', N'Größe', N'Groesse')
  ORDER BY a.MerkmalName
")->fetchAll(PDO::FETCH_ASSOC);

/* ====== Helpers: Normalisierung & SKU-Generierung ====== */
function norm_de(string $s): string {
  $map = ['Ä'=>'AE','Ö'=>'OE','Ü'=>'UE','ä'=>'AE','ö'=>'OE','ü'=>'UE','ß'=>'SS'];
  $s = strtr($s, $map);
  $s = iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$s);
  $s = strtoupper($s);
  $s = preg_replace('/[^A-Z0-9]+/', '', $s);
  return $s ?? '';
}
function color_code(?string $color): string {
  if (!$color) return '';
  $c = trim(mb_strtolower($color));
  $map = [
    'schwarz'=>'BK','black'=>'BK',
    'weiß'=>'WH','weiss'=>'WH','white'=>'WH',
    'rot'=>'RD','red'=>'RD',
    'blau'=>'BL','blue'=>'BL','navy'=>'NV','dunkelblau'=>'NV',
    'grün'=>'GN','gruen'=>'GN','green'=>'GN',
    'gelb'=>'YL','yellow'=>'YL',
    'orange'=>'OR',
    'grau'=>'GY','anthrazit'=>'AN','silber'=>'SV',
    'braun'=>'BR','beige'=>'BE',
    'violett'=>'VI','lila'=>'VI','purple'=>'PU',
  ];
  if (isset($map[$c])) return $map[$c];
  $norm = norm_de($color);
  return substr($norm, 0, 2);
}
function size_code(?string $size): string {
  if (!$size) return '';
  $s = strtoupper(trim($size));
  $s = str_replace([' ', '/'], '', $s);
  return preg_replace('/[^A-Z0-9\-]/', '', $s);
}
function sku_base(array $material): string {
  $base = (string)($material['BasisSKU'] ?? '');
  if ($base !== '') return norm_de($base);
  $fromName = norm_de((string)$material['MaterialName']);
  return substr($fromName, 0, 16);
}
function build_sku(array $material, ?string $color, ?string $size): string {
  $parts = [ sku_base($material) ];
  $cc = color_code($color);
  if ($cc !== '') $parts[] = $cc;
  $sc = size_code($size);
  if ($sc !== '') $parts[] = $sc;
  return implode('-', array_filter($parts, fn($p)=>$p !== ''));
}
function ensure_unique_sku(PDO $pdo, string $sku): string {
  if ($sku === '') return '';
  $base = $sku; $n = 1;
  $check = $pdo->prepare("SELECT 1 FROM dbo.MatVarianten WHERE SKU = :sku");
  while (true) {
    $check->execute([':sku'=>$sku]);
    if (!$check->fetchColumn()) return $sku;
    $n++; $sku = $base.'-'.$n;
  }
}

/* ====== Form-Values ====== */
$values = [
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
  if ($values['Barcode'] !== '' && mb_strlen($values['Barcode']) > 100) { $errors['Barcode'] = 'Barcode max. 100 Zeichen.'; }

  if ($merkmalIdFarbe && $values['Farbe'] !== '' && !in_array($values['Farbe'], $allowedColors, true)) {
    $errors['Farbe'] = 'Ungültige Farbe.';
  }

  if ($groupHasSizeProfile) {
    if ($values['Groesse'] === '') {
      $errors['Groesse'] = 'Bitte Größe wählen.';
    } elseif (!in_array($values['Groesse'], $allowedSizes, true)) {
      $errors['Groesse'] = 'Ungültige Größe.';
    }
  } else {
    if ($values['Farbe'] === '' && $values['Groesse'] === '') {
      $errors['Groesse'] = 'Bitte mindestens Farbe oder Größe angeben.';
    }
  }

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

  // Auto-Bezeichnung
  $parts = [];
  if ($values['Farbe']   !== '') $parts[] = trim((string)$values['Farbe']);
  if ($values['Groesse'] !== '') $parts[] = trim((string)$values['Groesse']);
  $autoName = implode(', ', $parts);
  if ($autoName === '') {
    $errors['_'] = 'Variante kann nicht ohne Farbe/Größe benannt werden.';
  }

  // SKU bestimmen
  $requestedSku = trim($values['SKU'] ?? '');
  if ($requestedSku === '') {
    $requestedSku = build_sku($material, $values['Farbe'] ?: null, $values['Groesse'] ?: null);
  }
  if ($requestedSku !== '' && mb_strlen($requestedSku) > 100) {
    $errors['SKU'] = 'SKU max. 100 Zeichen.';
  }
  $finalSku = null;
  if ($requestedSku !== '') {
    $finalSku = ensure_unique_sku($pdo, $requestedSku);
  }

  if (!$errors) {
    try {
      $pdo->beginTransaction();

      // Variante
      $sqlVar = "
        INSERT INTO dbo.MatVarianten (MaterialID, VariantenBezeichnung, SKU, Barcode, IsActive, CreatedAt)
        OUTPUT INSERTED.VarianteID
        VALUES (:mid, :name, :sku, :barcode, :act, SYSUTCDATETIME())
      ";
      $stIns = $pdo->prepare($sqlVar);
      $stIns->execute([
        ':mid'     => $materialId,
        ':name'    => $autoName,
        ':sku'     => $finalSku,
        ':barcode' => ($values['Barcode'] === '' ? null : $values['Barcode']),
        ':act'     => ($values['IsActive'] === '1' ? 1 : 0),
      ]);
      $varianteId = (int)$stIns->fetchColumn();

      // Attribute Farbe/Größe + weitere
      $insAttr = $pdo->prepare("
        INSERT INTO dbo.MatVariantenAttribute (VarianteID, MerkmalID, MerkmalWert)
        VALUES (:vid, :mid, :val)
      ");
      if ($merkmalIdFarbe && $values['Farbe'] !== '') {
        $insAttr->execute([':vid'=>$varianteId, ':mid'=>$merkmalIdFarbe,  ':val'=>$values['Farbe']]);
      }
      if ($merkmalIdGroesse && $values['Groesse'] !== '') {
        $insAttr->execute([':vid'=>$varianteId, ':mid'=>$merkmalIdGroesse, ':val'=>$values['Groesse']]);
      }
      foreach ($otherAttrValues as $mid => $val) {
        if ($val === '') continue;
        $insAttr->execute([':vid'=>$varianteId, ':mid'=>$mid, ':val'=>$val]);
      }

      $pdo->commit();
      $_SESSION['flash_success'] = 'Variante „'.$autoName.'“ wurde angelegt.'.($finalSku ? ' SKU: '.$finalSku : '');
      header('Location: '.$base.'/?p=variant_create&material_id='.$materialId);
      exit;

    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $msg = $e->getMessage();
      if (stripos($msg, 'UQ_Variante_Material_Bez') !== false) {
        $errors['_'] = 'Es existiert bereits eine Variante mit der Bezeichnung „'.$autoName.'“ für dieses Material.';
      } elseif (stripos($msg, 'UQ_Variante_SKU') !== false || stripos($msg, 'UX_MatVarianten_SKU_NotNull') !== false) {
        $errors['_'] = 'Die angegebene/erzeugte SKU ist bereits vergeben.';
      } else {
        $errors['_'] = 'Speichern fehlgeschlagen: '.$msg;
      }
    }
  }
}

/* ====== Bestehende Varianten laden ====== */
$existing = $pdo->prepare("
  SELECT v.VarianteID, v.VariantenBezeichnung, v.SKU, v.Barcode, v.IsActive,
         MAX(CASE WHEN a.MerkmalName = N'Farbe' THEN va.MerkmalWert END) AS Farbe,
         MAX(CASE WHEN a.MerkmalName IN (N'Größe', N'Groesse') THEN va.MerkmalWert END) AS Groesse
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

    <form method="post" action="" id="variantForm">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

      <div class="row-1">
        <div>
          <label>Variantenname (automatisch)</label>
          <div id="autoNamePreview" class="hint" style="font-weight:600;">—</div>
        </div>
      </div>

      <div class="row">
        <div>
          <label for="SKU">SKU / Artikelnummer (automatisch, anpassbar)</label>
          <input type="text" id="SKU" name="SKU" maxlength="100" value="<?= e($values['SKU']) ?>">
          <?php if (!empty($errors['SKU'])): ?><div class="alert alert-error"><?= e($errors['SKU']) ?></div><?php endif; ?>
          <div class="hint">Wird automatisch vorgeschlagen, kann aber überschrieben werden.</div>
        </div>
        <div>
          <label for="Barcode">Barcode</label>
          <input type="text" id="Barcode" name="Barcode" maxlength="100" value="<?= e($values['Barcode']) ?>">
          <?php if (!empty($errors['Barcode'])): ?><div class="alert alert-error"><?= e($errors['Barcode']) ?></div><?php endif; ?>
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
            <input type="text" id="Groesse" name="Groesse" value="<?= e($values['Groesse']) ?>" placeholder="(optional, wenn kein Profil)">
          <?php endif; ?>
          <?php if (!empty($errors['Groesse'])): ?><div class="alert alert-error"><?= e($errors['Groesse']) ?></div><?php endif; ?>
          <div class="hint">
            Größen kommen aus dem Profil der Gruppe „<?= e($material['Gruppenname']) ?>“.
          </div>
        </div>
      </div>

      <?php if (!empty($attrAll)): ?>
        <div class="row-1">
          <div>
            <label>Weitere Merkmale</label>
            <div class="hint">Optional. Falls „Allowed Values“ gepflegt sind, werden Auswahllisten angezeigt.</div>
          </div>
        </div>
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

      <div style="display:flex;align-items:center;gap:16px;margin-top:.5rem">
        <label style="display:flex;gap:.5rem;align-items:center;margin:0">
          <input type="checkbox" name="IsActive" value="1" <?= ($values['IsActive']==='1'?'checked':'') ?>> Aktiv
        </label>

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

  <div class="card">
    <h2>Hinweise</h2>
    <ul class="muted" style="margin-left:1rem">
      <li>Variantenname wird automatisch aus Farbe &amp; Größe gebildet.</li>
      <li>SKU wird automatisch vorgeschlagen (BasisSKU/Material + Farbcode + Größe) und auf Eindeutigkeit geprüft.</li>
      <li>Größenprofil kommt aus der Materialgruppe „<?= e($material['Gruppenname']) ?>“.</li>
      <li>Bei Gruppen ohne Größenprofil: Mindestens Farbe oder Größe angeben.</li>
    </ul>
    <div style="margin-top:1rem">
      <a class="btn btn-secondary" href="<?= e($base) ?>/?p=materials">Zur Materialliste</a>
    </div>
  </div>
</div>

<script>
  (function(){
    const basisSKU = "<?= e((string)($material['BasisSKU'] ?? '')) ?>";
    const materialName = "<?= e((string)$material['MaterialName']) ?>";

    function normDe(str){
      if(!str) return '';
      const map = {'Ä':'AE','Ö':'OE','Ü':'UE','ä':'AE','ö':'OE','ü':'UE','ß':'SS'};
      str = str.replace(/[ÄÖÜäöüß]/g, ch => map[ch] || ch);
      str = str.normalize('NFD').replace(/[\u0300-\u036f]/g,'');
      str = str.toUpperCase().replace(/[^A-Z0-9]+/g,'');
      return str;
    }
    function colorCode(c){
      if(!c) return '';
      const m = {
        'schwarz':'BK','black':'BK',
        'weiß':'WH','weiss':'WH','white':'WH',
        'rot':'RD','red':'RD',
        'blau':'BL','blue':'BL','navy':'NV','dunkelblau':'NV',
        'grün':'GN','gruen':'GN','green':'GN',
        'gelb':'YL','yellow':'YL',
        'orange':'OR',
        'grau':'GY','anthrazit':'AN','silber':'SV',
        'braun':'BR','beige':'BE',
        'violett':'VI','lila':'VI','purple':'PU'
      };
      const key = String(c).trim().toLowerCase();
      if(m[key]) return m[key];
      const n = normDe(c);
      return n.substring(0,2);
    }
    function sizeCode(s){
      if(!s) return '';
      s = String(s).toUpperCase().trim().replace(/[ \/]/g,'');
      return s.replace(/[^A-Z0-9\-]/g,'');
    }
    function skuBase(){
      if(basisSKU) return normDe(basisSKU);
      return normDe(materialName).substring(0,16);
    }
    function buildSku(color, size){
      const parts = [skuBase()];
      const cc = colorCode(color);
      if(cc) parts.push(cc);
      const sc = sizeCode(size);
      if(sc) parts.push(sc);
      return parts.join('-');
    }

    const f = document.getElementById('Farbe');
    const g = document.getElementById('Groesse');
    const outName = document.getElementById('autoNamePreview');
    const skuInput = document.getElementById('SKU');

    function updatePreview(){
      const color = f ? (f.value || '').trim() : '';
      const gsel = g ? (g.tagName === 'SELECT' ? (g.options[g.selectedIndex]?.text || '') : g.value) : '';
      const size = (gsel || '').trim();

      const parts = [];
      if(color) parts.push(f.options ? f.options[f.selectedIndex].text : color);
      if(size)  parts.push(size);
      outName.textContent = parts.length ? parts.join(', ') : '—';

      if(skuInput && skuInput.value.trim() === '') {
        skuInput.placeholder = buildSku(color, size);
      }
    }

    if (f) f.addEventListener('change', updatePreview);
    if (g) g.addEventListener('change', updatePreview);
    updatePreview();
  })();
</script>
