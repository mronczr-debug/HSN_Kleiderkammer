<?php
declare(strict_types=1);

/**
 * Wareneingang erfassen
 * - Kopfdaten (Lieferant, WE-Datum, LS-Nr, Bestell-Nr)
 * - Positionen (Kategorie -> Hersteller -> Artikel -> Variante, Menge)
 * - WE-Nummer (BelegNr) wird beim Buchen automatisch vergeben (sp_NextBelegNr)
 * - Bucht Materialbeleg mit Bewegungsart 101 (WE); Kopf in dbo.BelegKopf
 *
 * Erwartet: $pdo (PDO SQLSRV), Helpers: e(), csrf_token(), csrf_check(), base_url()
 */

$base = rtrim(base_url(), '/');

/* ------------------ Lookups laden ------------------ */
$gruppen = $pdo->query("SELECT MaterialgruppeID AS id, Gruppenname AS name FROM dbo.Materialgruppe ORDER BY Gruppenname")->fetchAll(PDO::FETCH_ASSOC);

/* Hersteller je Gruppe (nur solche, die in der Gruppe Materialien haben) */
$herstellerRaw = $pdo->query("
  SELECT DISTINCT m.MaterialgruppeID AS gid, h.HerstellerID AS id, h.Name AS name
  FROM dbo.Material m
  JOIN dbo.Hersteller h ON h.HerstellerID = m.HerstellerID
  WHERE m.MaterialgruppeID IS NOT NULL AND m.HerstellerID IS NOT NULL
")->fetchAll(PDO::FETCH_ASSOC);

/* Materialien (mit Gruppe & Hersteller) */
$materialRaw = $pdo->query("
  SELECT MaterialID AS id, MaterialName AS name, MaterialgruppeID AS gid, HerstellerID AS hid
  FROM dbo.Material
  ORDER BY MaterialName
")->fetchAll(PDO::FETCH_ASSOC);

/* Varianten je Material */
$variantenRaw = $pdo->query("
  SELECT VarianteID AS id, VariantenBezeichnung AS name, MaterialID AS mid
  FROM dbo.MatVarianten
  ORDER BY VariantenBezeichnung
")->fetchAll(PDO::FETCH_ASSOC);

/* optional: Lagerorte (falls später gewünscht) – aktuell nicht gefordert */
// $lagerorte = $pdo->query("SELECT LagerortID AS id, Bezeichnung AS name FROM dbo.Lagerort WHERE IsActive=1 ORDER BY Bezeichnung")->fetchAll(PDO::FETCH_ASSOC);

/* Daten für JS als strukturierte Maps aufbereiten */
$herstellerByGroup = [];
foreach ($herstellerRaw as $r) {
  $gid = (int)$r['gid'];
  $herstellerByGroup[$gid][] = ['id'=>(int)$r['id'], 'name'=>$r['name']];
}
$materialsByGroupMan = [];
foreach ($materialRaw as $m) {
  $gid = (int)$m['gid']; $hid = (int)$m['hid'];
  $materialsByGroupMan[$gid][$hid][] = ['id'=>(int)$m['id'], 'name'=>$m['name']];
}
$variantsByMaterial = [];
foreach ($variantenRaw as $v) {
  $mid = (int)$v['mid'];
  $variantsByMaterial[$mid][] = ['id'=>(int)$v['id'], 'name'=>$v['name']];
}

/* ------------------ Formular-Handling ------------------ */
$errors = [];
$flashSuccess = $_SESSION['flash_success'] ?? null; unset($_SESSION['flash_success']);

/* Defaults */
$valHead = [
  'csrf'      => $_POST['csrf'] ?? '',
  'lieferant' => trim((string)($_POST['lieferant'] ?? '')),
  'datum'     => $_POST['datum'] ?? date('Y-m-d'),
  'lsnr'      => trim((string)($_POST['lsnr'] ?? '')),
  'bestnr'    => trim((string)($_POST['bestnr'] ?? '')),
];

$positions = $_POST['pos'] ?? []; // Array von Zeilen: [['gid'=>..,'hid'=>..,'mid'=>..,'vid'=>..,'qty'=>..], ...]
if (!is_array($positions)) $positions = [];

/* --- Buchen --- */
if (($_POST['action'] ?? '') === 'buchen') {
  if (!csrf_check($valHead['csrf'])) {
    $errors['_'] = 'Sicherheits-Token ungültig. Bitte Seite neu laden.';
  }

  // Kopfdaten prüfen
  $d = DateTime::createFromFormat('Y-m-d', $valHead['datum']);
  if (!$d) { $errors['datum'] = 'Ungültiges Datum (YYYY-MM-DD).'; }

  // Mindestens eine Position?
  if (empty($positions)) {
    $errors['pos'] = 'Mindestens eine Position erfassen.';
  }

  // Positionen prüfen
  $cleanPos = [];
  $posIndex = 0;
  foreach ($positions as $idx => $p) {
    $gid = (int)($p['gid'] ?? 0);
    $hid = (int)($p['hid'] ?? 0);
    $mid = (int)($p['mid'] ?? 0);
    $vid = (int)($p['vid'] ?? 0);
    $qty = (string)($p['qty'] ?? '');

    if ($gid<=0 && $hid<=0 && $mid<=0 && $vid<=0 && trim($qty)==='') {
      // komplett leere Zeile ignorieren
      continue;
    }

    if ($gid<=0)  { $errors["pos_$idx"] = 'Kategorie fehlt.'; }
    if ($hid<=0)  { $errors["pos_$idx"] = ($errors["pos_$idx"] ?? '').' Hersteller fehlt.'; }
    if ($mid<=0)  { $errors["pos_$idx"] = ($errors["pos_$idx"] ?? '').' Artikel fehlt.'; }
    if ($vid<=0)  { $errors["pos_$idx"] = ($errors["pos_$idx"] ?? '').' Variante fehlt.'; }
    if ($qty==='' || !is_numeric($qty) || (float)$qty <= 0) {
      $errors["pos_$idx"] = ($errors["pos_$idx"] ?? '').' Menge > 0 erforderlich.';
    }

    $posIndex++;
    $cleanPos[] = [
      'pos' => $posIndex,
      'gid' => $gid,
      'hid' => $hid,
      'mid' => $mid,
      'vid' => $vid,
      'qty' => (float)$qty,
    ];
  }

  if (!$errors) {
    try {
      $pdo->beginTransaction();

      // 1) WE-Nummer aus DB holen
      $stmtNr = $pdo->prepare("DECLARE @nr NVARCHAR(40); EXEC dbo.sp_NextBelegNr @Vorgang=:v, @BelegNr=@nr OUTPUT; SELECT @nr;");
      $stmtNr->execute([':v'=>'WE']);
      $belegNr = (string)$stmtNr->fetchColumn();

      // 2) Kopf anlegen
      $stmtK = $pdo->prepare("
        INSERT INTO dbo.BelegKopf (BelegNr, Vorgang, BelegDatum, Lieferant, LSNummer, BestellNummer, CreatedAt)
        VALUES (:nr, 'WE', :dt, :lieferant, :ls, :best, SYSUTCDATETIME())
      ");
      $stmtK->execute([
        ':nr'        => $belegNr,
        ':dt'        => $valHead['datum'],
        ':lieferant' => ($valHead['lieferant'] === '' ? null : $valHead['lieferant']),
        ':ls'        => ($valHead['lsnr'] === '' ? null : $valHead['lsnr']),
        ':best'      => ($valHead['bestnr'] === '' ? null : $valHead['bestnr']),
      ]);

      // 3) Positionen in Materialbeleg (WE = 101)
      $stmtP = $pdo->prepare("
        INSERT INTO dbo.Materialbeleg
          (BelegNr, Position, Buchungsdatum, BewegungsartID, VarianteID, LagerortID, Menge, MitarbeiterID, ReferenzBelegID, Bemerkung, CreatedAt)
        VALUES
          (:nr, :pos, :bd, 101, :vid, NULL, :qty, NULL, NULL, NULL, SYSUTCDATETIME())
      ");
      foreach ($cleanPos as $p) {
        $stmtP->execute([
          ':nr'  => $belegNr,
          ':pos' => (int)$p['pos'],
          ':bd'  => $valHead['datum'], // Datum aus Kopf
          ':vid' => (int)$p['vid'],
          ':qty' => $p['qty'],
        ]);
      }

      $pdo->commit();
      $_SESSION['flash_success'] = 'Wareneingang gebucht. WE-Nummer: '.$belegNr;
      header('Location: '.$base.'/?p=wareneingang');
      exit;

    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $errors['_'] = 'Buchen fehlgeschlagen: '.$e->getMessage();
    }
  }
}

?>
<div class="card">
  <h1>Wareneingang erfassen</h1>
  <?php if ($flashSuccess): ?>
    <div class="alert alert-success"><?= e($flashSuccess) ?></div>
  <?php endif; ?>
  <?php if (!empty($errors['_'])): ?>
    <div class="alert alert-error"><?= e($errors['_']) ?></div>
  <?php endif; ?>

  <!-- Tabs für Unterbereiche -->
  <div class="tabs" style="margin-bottom:12px;display:flex;gap:8px;flex-wrap:wrap">
    <a class="btn btn-secondary" href="<?= e($base) ?>/?p=wareneingang">Wareneingang</a>
    <a class="btn btn-secondary" href="<?= e($base) ?>/?p=wareneingang_korrektur">WE Korrektur</a>
    <span class="btn" style="pointer-events:none;opacity:.6">Warenausgang (folgt)</span>
    <span class="btn" style="pointer-events:none;opacity:.6">Inventur (folgt)</span>
  </div>

  <form method="post" id="we-form">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="buchen">

    <div class="grid2">
      <div>
        <label for="lieferant">Lieferant</label>
        <input type="text" id="lieferant" name="lieferant" value="<?= e($valHead['lieferant']) ?>" placeholder="frei wählbar">
      </div>
      <div>
        <label for="datum">WE-Datum</label>
        <input type="date" id="datum" name="datum" value="<?= e($valHead['datum']) ?>" required>
        <?php if (!empty($errors['datum'])): ?><div class="alert alert-error"><?= e($errors['datum']) ?></div><?php endif; ?>
      </div>
    </div>

    <div class="grid2">
      <div>
        <label for="lsnr">Lieferscheinnummer</label>
        <input type="text" id="lsnr" name="lsnr" value="<?= e($valHead['lsnr']) ?>">
      </div>
      <div>
        <label for="bestnr">Bestellnummer</label>
        <input type="text" id="bestnr" name="bestnr" value="<?= e($valHead['bestnr']) ?>">
      </div>
    </div>

    <h2 style="margin-top:1rem">Positionen</h2>
    <?php if (!empty($errors['pos'])): ?><div class="alert alert-error"><?= e($errors['pos']) ?></div><?php endif; ?>

    <table id="posTable">
      <thead>
        <tr>
          <th style="width:70px">Pos.</th>
          <th>Kategorie</th>
          <th>Hersteller</th>
          <th>Artikel</th>
          <th>Variante</th>
          <th style="width:130px">Menge</th>
          <th style="width:60px"></th>
        </tr>
      </thead>
      <tbody></tbody>
    </table>

    <div style="margin-top:.6rem;display:flex;gap:8px">
      <button class="btn btn-secondary" type="button" id="addRowBtn">+ Position</button>
      <button class="btn btn-primary" type="submit">WE buchen</button>
      <a class="btn btn-secondary" href="<?= e($base) ?>/?p=wareneingang">Verwerfen</a>
    </div>
  </form>
</div>

<script>
(function(){
  const data = {
    groups: <?= json_encode($gruppen, JSON_UNESCAPED_UNICODE) ?>,
    herstellerByGroup: <?= json_encode($herstellerByGroup, JSON_UNESCAPED_UNICODE) ?>,
    materialsByGroupMan: <?= json_encode($materialsByGroupMan, JSON_UNESCAPED_UNICODE) ?>,
    variantsByMaterial: <?= json_encode($variantsByMaterial, JSON_UNESCAPED_UNICODE) ?>,
    errorsByRow: <?= json_encode(array_filter($errors, fn($k)=>str_starts_with((string)$k,'pos_'), ARRAY_FILTER_USE_KEY)) ?>,
    postedRows: <?= json_encode($positions, JSON_UNESCAPED_UNICODE) ?>
  };

  const tbody = document.querySelector('#posTable tbody');
  const addBtn = document.getElementById('addRowBtn');

  function el(tag, attrs={}, children=[]){
    const e = document.createElement(tag);
    for (const [k,v] of Object.entries(attrs)) {
      if (k==='class') e.className = v;
      else if (k==='value') e.value = v;
      else e.setAttribute(k,v);
    }
    (Array.isArray(children)?children:[children]).forEach(c=>{ if(c!==null&&c!==undefined) e.appendChild(typeof c==='string'?document.createTextNode(c):c); });
    return e;
  }

  function buildSelect(options, placeholder='— bitte wählen —', value=''){
    const sel = el('select');
    sel.appendChild(el('option', {value:''}, placeholder));
    options.forEach(o=>{
      const opt = el('option', {value:String(o.id)}, o.name);
      if (String(value)===String(o.id)) opt.selected = true;
      sel.appendChild(opt);
    });
    return sel;
  }

  function fillHersteller(sel, gid, value){
    const opts = data.herstellerByGroup[gid] || [];
    const n = buildSelect(opts, '— bitte wählen —', value);
    sel.replaceWith(n);
    return n;
  }
  function fillMaterial(sel, gid, hid, value){
    const opts = (data.materialsByGroupMan[gid]||{})[hid] || [];
    const n = buildSelect(opts, '— bitte wählen —', value);
    sel.replaceWith(n);
    return n;
  }
  function fillVariant(sel, mid, value){
    const opts = data.variantsByMaterial[mid] || [];
    const n = buildSelect(opts, '— bitte wählen —', value);
    sel.replaceWith(n);
    return n;
  }

  function renumber(){
    [...tbody.querySelectorAll('tr')].forEach((tr, i)=>{
      tr.querySelector('.pos-cell').textContent = (i+1);
      tr.querySelectorAll('select, input').forEach(inp=>{
        const name = inp.getAttribute('name');
        if (!name) return;
        const newName = name.replace(/pos\[\d+\]/, 'pos['+(i)+']');
        inp.setAttribute('name', newName);
      });
    });
  }

  function addRow(pref=null){
    const idx = tbody.querySelectorAll('tr').length;
    const tr = el('tr');
    const tdPos = el('td', {class:'pos-cell'});
    const tdG = el('td'), tdH=el('td'), tdM=el('td'), tdV=el('td'), tdQ=el('td'), tdX=el('td');

    // Kategorie
    let selG = buildSelect(data.groups, '— bitte wählen —', pref?.gid || '');
    selG.name = `pos[${idx}][gid]`; selG.required=true;
    // Hersteller
    let selH = buildSelect([], '— bitte wählen —', pref?.hid || '');
    selH.name = `pos[${idx}][hid]`; selH.required=true;
    // Material
    let selM = buildSelect([], '— bitte wählen —', pref?.mid || '');
    selM.name = `pos[${idx}][mid]`; selM.required=true;
    // Variante
    let selV = buildSelect([], '— bitte wählen —', pref?.vid || '');
    selV.name = `pos[${idx}][vid]`; selV.required=true;
    // Menge
    let inpQ = el('input', {type:'number', step:'0.001', min:'0', name:`pos[${idx}][qty]`, value: (pref?.qty || '') });

    tdG.appendChild(selG); tdH.appendChild(selH); tdM.appendChild(selM); tdV.appendChild(selV); tdQ.appendChild(inpQ);
    const btnDel = el('button', {type:'button', class:'btn btn-secondary'}, '–');
    btnDel.addEventListener('click', ()=>{ tr.remove(); renumber(); });

    tdX.appendChild(btnDel);
    tr.appendChild(tdPos); tr.appendChild(tdG); tr.appendChild(tdH); tr.appendChild(tdM); tr.appendChild(tdV); tr.appendChild(tdQ); tr.appendChild(tdX);
    tbody.appendChild(tr);
    renumber();

    function onG(){
      selH = fillHersteller(selH, parseInt(selG.value||'0',10), pref?.hid || '');
      selH.name = `pos[${idx}][hid]`; selH.required=true;
      selH.addEventListener('change', onH);
      onH();
    }
    function onH(){
      selM = fillMaterial(selM, parseInt(selG.value||'0',10), parseInt(selH.value||'0',10), pref?.mid || '');
      selM.name = `pos[${idx}][mid]`; selM.required=true;
      selM.addEventListener('change', onM);
      onM();
    }
    function onM(){
      selV = fillVariant(selV, parseInt(selM.value||'0',10), pref?.vid || '');
      selV.name = `pos[${idx}][vid]`; selV.required=true;
    }

    selG.addEventListener('change', onG);
    onG(); // initial befüllen

    // Fehlerhinweis (falls vorhanden)
    const err = data.errorsByRow[`pos_${idx}`];
    if (err) {
      const div = el('div', {class:'alert alert-error'}, err);
      tr.lastChild.appendChild(div);
    }
  }

  addBtn.addEventListener('click', ()=>addRow());

  // vorgepostete Zeilen wiederherstellen
  if (Array.isArray(data.postedRows) && data.postedRows.length) {
    data.postedRows.forEach(r => addRow(r));
  } else {
    addRow();
  }
})();
</script>
