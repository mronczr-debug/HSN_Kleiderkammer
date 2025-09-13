<?php
// app/pages/ausgabe_bestellung_neu.php
declare(strict_types=1);
session_start();

/* ========= Minimal-Helpers ========= */
if (!function_exists('e')) { function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); } }
if (!function_exists('csrf_token')) { function csrf_token(): string { if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16)); return $_SESSION['csrf']; } }
if (!function_exists('csrf_check')) { function csrf_check(string $t): bool { return hash_equals($_SESSION['csrf'] ?? '', $t); } }
if (!function_exists('flash')) {
  function flash(string $key, ?string $msg=null): ?string {
    if ($msg !== null) { $_SESSION['flash_'.$key] = $msg; return null; }
    $val = $_SESSION['flash_'.$key] ?? null; unset($_SESSION['flash_'.$key]); return $val;
  }
}
if (!function_exists('windows_user')) {
  function windows_user(): string {
    $candidates = [
      $_SERVER['AUTH_USER']   ?? null,
      $_SERVER['REMOTE_USER'] ?? null,
      $_SERVER['LOGON_USER']  ?? null,
      $_SERVER['USERNAME']    ?? null,
    ];
    foreach ($candidates as $u) { $u = trim((string)$u); if ($u!=='') return mb_substr($u,0,256); }
    return 'unknown';
  }
}

/* ========= DB Connect ========= */
$dsn  = 'sqlsrv:Server=NAUSWIASPSQL01;Database=Arbeitskleidung';
$user = 'HSN_DB1';
$pass = 'HSNdb1';
try {
  $pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE         => PDO::ERRMODE_EXCEPTION,
    PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8
  ]);
} catch (Throwable $e) { http_response_code(500); exit('DB-Verbindung fehlgeschlagen: '.e($e->getMessage())); }

/* ========= AJAX: Mitarbeiter-Suche ========= */
if (($_GET['action'] ?? '') === 'employee_search') {
  header('Content-Type: application/json; charset=utf-8');
  $term = trim((string)($_GET['term'] ?? ''));
  if ($term === '') { echo json_encode([]); exit; }
  $stmt = $pdo->prepare("
    SELECT TOP (20)
      ms.MitarbeiterID AS id,
      ms.Personalnummer AS pn,
      ms.Nachname, ms.Vorname,
      ms.Abteilung,
      mt.Bezeichnung AS Typ,
      mk.Bezeichnung AS Kategorie
    FROM dbo.MitarbeiterStamm ms
    JOIN dbo.MitarbeiterTyp mt ON mt.TypID = ms.MitarbeiterTypID
    JOIN dbo.MitarbeiterKategorie mk ON mk.KategorieID = ms.MitarbeiterKategorieID
    WHERE ms.Personalnummer LIKE :t OR ms.Nachname LIKE :t OR ms.Vorname LIKE :t
    ORDER BY ms.Nachname, ms.Vorname
  ");
  $stmt->execute([':t'=>'%'.$term.'%']);
  $out = [];
  while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $out[] = [
      'id' => (int)$r['id'],
      'label' => $r['Nachname'].', '.$r['Vorname'].' ('.$r['pn'].')',
      'pn' => $r['pn'],
      'info' => [
        'abteilung' => $r['Abteilung'],
        'typ' => $r['Typ'],
        'kategorie' => $r['Kategorie'],
      ]
    ];
  }
  echo json_encode($out, JSON_UNESCAPED_UNICODE);
  exit;
}

/* ========= Parametrisierung: neu vs. edit ========= */
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$typ = $_GET['typ'] ?? '';
if ($editId > 0) {
  $row = $pdo->prepare("SELECT * FROM dbo.MitarbeiterBestellungKopf WHERE BestellungID = :id");
  $row->execute([':id'=>$editId]);
  $kopf = $row->fetch(PDO::FETCH_ASSOC);
  if (!$kopf) { http_response_code(404); exit('Bestellung nicht gefunden.'); }
  $typ = $kopf['Typ'];
  if ($typ!=='A' && $typ!=='R') { http_response_code(400); exit('Ungültiger Typ.'); }
} else {
  if ($typ!=='A' && $typ!=='R') { http_response_code(400); exit('Parameter typ=A|R erforderlich.'); }
  $kopf = null;
}

/* ========= Mitarbeiter-Info (bei Edit) ========= */
$mitarbeiterInfo = null;
if ($kopf) {
  $st = $pdo->prepare("
    SELECT ms.MitarbeiterID, ms.Personalnummer, ms.Nachname, ms.Vorname, ms.Abteilung,
           mt.Bezeichnung AS Typ, mk.Bezeichnung AS Kategorie
    FROM dbo.MitarbeiterStamm ms
    JOIN dbo.MitarbeiterTyp mt ON mt.TypID = ms.MitarbeiterTypID
    JOIN dbo.MitarbeiterKategorie mk ON mk.KategorieID = ms.MitarbeiterKategorieID
    WHERE ms.MitarbeiterID = :id
  ");
  $st->execute([':id'=>$kopf['MitarbeiterID']]);
  $mitarbeiterInfo = $st->fetch(PDO::FETCH_ASSOC);
}

/* ========= Daten für Kaskaden (Ausgabe) ========= */
$gruppen = $pdo->query("SELECT MaterialgruppeID AS id, Gruppenname AS name FROM dbo.Materialgruppe ORDER BY Gruppenname")->fetchAll(PDO::FETCH_ASSOC);
$materialRaw = $pdo->query("
  SELECT m.MaterialID AS id, m.MaterialName AS name, m.MaterialgruppeID AS gid, m.HerstellerID AS hid
  FROM dbo.Material m
  ORDER BY m.MaterialName
")->fetchAll(PDO::FETCH_ASSOC);
$herstellerRaw = $pdo->query("
  SELECT DISTINCT m.MaterialgruppeID AS gid, h.HerstellerID AS id, h.Name AS name
  FROM dbo.Material m
  JOIN dbo.Hersteller h ON h.HerstellerID = m.HerstellerID
  WHERE m.MaterialgruppeID IS NOT NULL AND m.HerstellerID IS NOT NULL
  ORDER BY h.Name
")->fetchAll(PDO::FETCH_ASSOC);
$variantenRaw = $pdo->query("
  SELECT v.VarianteID AS id, v.VariantenBezeichnung AS name, v.MaterialID AS mid
  FROM dbo.MatVarianten v
  ORDER BY v.VariantenBezeichnung
")->fetchAll(PDO::FETCH_ASSOC);

/* ========= Daten für Kaskaden (Rückgabe – harte Begrenzung) ========= */
$offenByEmp = [];
if ($typ==='R') {
  $empId = $kopf['MitarbeiterID'] ?? 0; // Bei Neuanlage erst nach Auswahl verfügbar (AJAX)
  if ($empId) {
    $st = $pdo->prepare("
      SELECT avo.VarianteID, avo.Offen,
             v.MaterialID, m.MaterialgruppeID AS gid, m.HerstellerID AS hid
      FROM dbo.v_Mitarbeiter_AusgabeOffen avo
      JOIN dbo.MatVarianten v ON v.VarianteID = avo.VarianteID
      JOIN dbo.Material m ON m.MaterialID = v.MaterialID
      WHERE avo.MitarbeiterID = :mid
    ");
    $st->execute([':mid'=>$empId]);
    $off = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($off as $x) {
      $offenByEmp[(int)$x['VarianteID']] = [
        'offen'=>(float)$x['Offen'], 'mid'=>(int)$x['MaterialID'],
        'gid'=>(int)$x['gid'], 'hid'=>(int)$x['hid']
      ];
    }
  }
}

/* ========= Bestehende Positionen (bei Edit) ========= */
$posRows = [];
if ($kopf) {
  $ps = $pdo->prepare("
    SELECT p.PosNr, p.VarianteID, p.Menge, v.MaterialID, m.MaterialgruppeID AS gid, m.HerstellerID AS hid
    FROM dbo.MitarbeiterBestellungPos p
    JOIN dbo.MatVarianten v ON v.VarianteID = p.VarianteID
    JOIN dbo.Material m ON m.MaterialID = v.MaterialID
    WHERE p.BestellungID = :bid
    ORDER BY p.PosNr
  ");
  $ps->execute([':bid'=>$kopf['BestellungID']]);
  $posRows = $ps->fetchAll(PDO::FETCH_ASSOC);
}

/* ========= POST: Speichern ========= */
$errors = [];
if (($_POST['action'] ?? '') === 'save') {
  $val = [
    'csrf' => $_POST['csrf'] ?? '',
    'typ'  => $_POST['typ'] ?? '',
    'mid'  => (int)($_POST['MitarbeiterID'] ?? 0),
    'grund'=> trim((string)($_POST['RueckgabeGrund'] ?? '')),
    'hinweis'=> trim((string)($_POST['Hinweis'] ?? '')),
  ];
  $pos = $_POST['pos'] ?? []; if (!is_array($pos)) $pos=[];
  if (!csrf_check($val['csrf'])) { $errors['_']='Sicherheits-Token ungültig. Bitte Seite neu laden.'; }

  if ($val['typ']!=='A' && $val['typ']!=='R') $errors['_']='Ungültiger Typ.';
  if ($val['mid']<=0) $errors['MitarbeiterID']='Bitte Mitarbeiter auswählen.';
  if ($val['typ']==='R' && $val['grund']==='') $errors['RueckgabeGrund']='Bitte Rückgabegrund angeben.';

  $clean = []; $ix=0;
  foreach ($pos as $i=>$p) {
    $gid=(int)($p['gid']??0); $hid=(int)($p['hid']??0); $mid=(int)($p['mid']??0); $vid=(int)($p['vid']??0);
    $qty=(string)($p['qty']??'');
    if ($gid<=0 && $hid<=0 && $mid<=0 && $vid<=0 && trim($qty)==='') continue;
    if ($gid<=0 || $hid<=0 || $mid<=0 || $vid<=0) { $errors["pos_$i"]='Bitte Kategorie/Hersteller/Artikel/Variante wählen.'; continue; }
    if ($qty==='' || !is_numeric($qty) || (float)$qty<=0) { $errors["pos_$i"]=($errors["pos_$i"]??'').' Menge > 0 erforderlich.'; continue; }
    $ix++; $clean[]=['pos'=>$ix,'vid'=>$vid,'qty'=>(float)$qty];
  }
  if (!$clean) $errors['pos']='Mindestens eine Position erfassen.';

  if ($val['typ']==='R' && $val['mid']>0) {
    // Serverseitige Zusatzprüfung gegen View (redundant zum DB-Trigger, aber bessere UX)
    $stmt = $pdo->prepare("
      SELECT avo.VarianteID, avo.Offen
      FROM dbo.v_Mitarbeiter_AusgabeOffen avo
      WHERE avo.MitarbeiterID = :mid AND avo.VarianteID = :vid
    ");
    foreach ($clean as $c) {
      $stmt->execute([':mid'=>$val['mid'], ':vid'=>$c['vid']]);
      $r = $stmt->fetch(PDO::FETCH_ASSOC);
      if (!$r) { $errors['pos']='Eine Variante ist für diesen Mitarbeiter nicht rückgabefähig.'; break; }
      if ($c['qty'] > (float)$r['Offen']) { $errors['pos']='Rückgabemenge überschreitet offene Menge.'; break; }
    }
  }

  if (!$errors) {
    try {
      $pdo->beginTransaction();

      $createdBy = windows_user();

      if ($editId>0) {
        // Update Kopf
        $stmt = $pdo->prepare("
          UPDATE dbo.MitarbeiterBestellungKopf
            SET Typ = :typ, MitarbeiterID = :mid, Hinweis = :hinweis,
                RueckgabeGrund = :grund
          WHERE BestellungID = :id
        ");
        $stmt->execute([
          ':typ'=>$val['typ'], ':mid'=>$val['mid'],
          ':hinweis'=>($val['hinweis']===''?null:$val['hinweis']),
          ':grund'=>($val['typ']==='R' ? $val['grund'] : null),
          ':id'=>$editId
        ]);

        // Positionen neu aufbauen
        $pdo->prepare("DELETE FROM dbo.MitarbeiterBestellungPos WHERE BestellungID = :id")->execute([':id'=>$editId]);
        $ins = $pdo->prepare("INSERT INTO dbo.MitarbeiterBestellungPos (BestellungID, PosNr, VarianteID, Menge) VALUES (:bid,:pos,:vid,:qty)");
        foreach ($clean as $c) $ins->execute([':bid'=>$editId, ':pos'=>$c['pos'], ':vid'=>$c['vid'], ':qty'=>$c['qty']]);

        $pdo->commit();
        flash('success','Bestellung wurde aktualisiert.');
        header('Location: ?p=ausgabe'); exit;

      } else {
        // Neue BestellNr
        $proc = $pdo->prepare("DECLARE @nr NVARCHAR(40); EXEC dbo.sp_NextBelegNr @Vorgang=:v, @BelegNr=@nr OUTPUT; SELECT @nr;");
        $proc->execute([':v'=>$val['typ']==='A'?'AO':'RO']);
        $bestellNr = (string)$proc->fetchColumn();

        // Kopf anlegen
        $stmt = $pdo->prepare("
          INSERT INTO dbo.MitarbeiterBestellungKopf
            (BestellNr, Typ, MitarbeiterID, BestellDatum, Status, Hinweis, RueckgabeGrund, CreatedAt, CreatedBy)
          VALUES
            (:nr, :typ, :mid, SYSUTCDATETIME(), 0, :hinweis, :grund, SYSUTCDATETIME(), :by);
          SELECT SCOPE_IDENTITY();
        ");
        $stmt->execute([
          ':nr'=>$bestellNr, ':typ'=>$val['typ'], ':mid'=>$val['mid'],
          ':hinweis'=>($val['hinweis']===''?null:$val['hinweis']),
          ':grund'=>($val['typ']==='R' ? $val['grund'] : null),
          ':by'=>$createdBy
        ]);
        $bid = (int)$stmt->fetchColumn();

        // Positionen
        $ins = $pdo->prepare("INSERT INTO dbo.MitarbeiterBestellungPos (BestellungID, PosNr, VarianteID, Menge) VALUES (:bid,:pos,:vid,:qty)");
        foreach ($clean as $c) $ins->execute([':bid'=>$bid, ':pos'=>$c['pos'], ':vid'=>$c['vid'], ':qty'=>$c['qty']]);

        $pdo->commit();
        flash('success','Bestellung wurde angelegt. Nr: '.$bestellNr);
        header('Location: ?p=ausgabe'); exit;
      }
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $errors['_'] = 'Speichern fehlgeschlagen: '.$e->getMessage();
    }
  }
}

/* ========= UI ========= */
$title = ($typ==='A' ? ($editId?'Bestellung bearbeiten':'Neue Bestellung (Ausgabe)') : ($editId?'Rückgabe bearbeiten':'Neue Rückgabe (Bestellung)'));
require __DIR__.'/../layout.php';
layout_header($title);
?>
  <div class="card">
    <h1><?= e($title) ?></h1>

    <?php if (!empty($errors['_'])): ?>
      <div class="alert alert-error"><?= e($errors['_']) ?></div>
    <?php endif; ?>

    <form method="post" id="form">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="typ" value="<?= e($typ) ?>">

      <div class="grid2">
        <div>
          <label for="mit_search">Mitarbeiter suchen</label>
          <div style="display:flex;gap:8px">
            <input type="text" id="mit_search" placeholder="Name oder Personalnummer...">
            <button type="button" class="btn btn-secondary" id="mit_clear">Zurücksetzen</button>
          </div>
          <input type="hidden" name="MitarbeiterID" id="MitarbeiterID" value="<?= e($kopf['MitarbeiterID'] ?? '') ?>">
          <?php if (!empty($errors['MitarbeiterID'])): ?><div class="alert alert-error"><?= e($errors['MitarbeiterID']) ?></div><?php endif; ?>
          <div id="mit_result" class="hint" style="margin-top:.3rem">
            <?php if ($mitarbeiterInfo): ?>
              Gewählt: <strong><?= e($mitarbeiterInfo['Nachname'].', '.$mitarbeiterInfo['Vorname'].' ('.$mitarbeiterInfo['Personalnummer'].')') ?></strong>
            <?php else: ?>
              Noch kein Mitarbeiter gewählt.
            <?php endif; ?>
          </div>
        </div>

        <div>
          <label for="Hinweis">Hinweis</label>
          <input type="text" id="Hinweis" name="Hinweis" value="<?= e($kopf['Hinweis'] ?? '') ?>">
        </div>
      </div>

      <?php if ($typ==='R'): ?>
        <div class="grid1">
          <div>
            <label for="RueckgabeGrund">Rückgabegrund *</label>
            <input type="text" id="RueckgabeGrund" name="RueckgabeGrund" required value="<?= e($kopf['RueckgabeGrund'] ?? '') ?>">
            <?php if (!empty($errors['RueckgabeGrund'])): ?><div class="alert alert-error"><?= e($errors['RueckgabeGrund']) ?></div><?php endif; ?>
          </div>
        </div>
      <?php endif; ?>

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
            <th style="width:140px">Menge</th>
            <th style="width:60px"></th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>

      <div style="margin-top:.6rem;display:flex;gap:8px">
        <button class="btn btn-secondary" type="button" id="addRowBtn">+ Position</button>
        <button class="btn btn-primary" type="submit">Speichern</button>
        <a class="btn btn-secondary" href="?p=ausgabe">Verwerfen</a>
      </div>
    </form>
  </div>

  <script>
  (function(){
    const TYP = <?= json_encode($typ) ?>;
    const EDIT = <?= json_encode($editId>0) ?>;

    const groups = <?= json_encode($gruppen, JSON_UNESCAPED_UNICODE) ?>;
    const mats = <?= json_encode($materialRaw, JSON_UNESCAPED_UNICODE) ?>;
    const mans = <?= json_encode($herstellerRaw, JSON_UNESCAPED_UNICODE) ?>;
    const varsAll = <?= json_encode($variantenRaw, JSON_UNESCAPED_UNICODE) ?>;

    // bei Typ R: bereits geladene "offen"-Varianten (nur bei Edit vorhanden)
    const offenMap = <?= json_encode($offenByEmp, JSON_UNESCAPED_UNICODE) ?>;

    // bestehende Positionen (bei Edit)
    const postedRows = <?= json_encode($posRows, JSON_UNESCAPED_UNICODE) ?>;

    // state: Mitarbeiter & "offen" (wird nach Auswahl via AJAX geladen)
    let currentEmployeeId = <?= (int)($kopf['MitarbeiterID'] ?? 0) ?>;
    let offenByVarianteId = {...offenMap}; // {vid: {offen, mid, gid, hid}}

    // Helpers
    function el(tag, attrs={}, children=[]){
      const e = document.createElement(tag);
      for (const [k,v] of Object.entries(attrs)) {
        if (k==='class') e.className = v;
        else if (k==='value') e.value = v;
        else e.setAttribute(k, v);
      }
      (Array.isArray(children)?children:[children]).forEach(c=>{
        if (c===null||c===undefined) return;
        e.appendChild(typeof c==='string'?document.createTextNode(c):c);
      });
      return e;
    }
    function buildSelect(options, placeholder='— bitte wählen —', value=''){
      const sel = el('select');
      sel.appendChild(el('option', {value:''}, placeholder));
      options.forEach(o=>{
        const opt = el('option', {value:String(o.id)}, o.name ?? o.label ?? String(o.id));
        if (String(value)===String(o.id)) opt.selected = true;
        sel.appendChild(opt);
      });
      return sel;
    }

    // Kaskaden-Quellen
    function herstellerByGroup(gid){
      return mans.filter(x=>String(x.gid)===String(gid));
    }
    function materialsByGH(gid, hid){
      return mats.filter(x=>String(x.gid)===String(gid) && String(x.hid)===String(hid));
    }
    function variantsByMaterial(mid){
      return varsAll.filter(x=>String(x.mid)===String(mid));
    }

    // Für Rückgabe: gefilterte Varianten & Kaskaden aus offenByVarianteId
    function filteredCascade(){
      const vids = Object.keys(offenByVarianteId).map(Number);
      const set = new Set(vids);
      const fVars = varsAll.filter(v=>set.has(Number(v.id)));
      const fMids = new Set(fVars.map(v=>Number(v.mid)));
      const matsF = mats.filter(m=>fMids.has(Number(m.id)));

      const gids = new Set(matsF.map(m=>Number(m.gid)));
      const groupsF = groups.filter(g=>gids.has(Number(g.id)));

      const pairGH = new Set(matsF.map(m=>m.gid+'|'+m.hid));
      const mansF = mans.filter(h=>pairGH.has(h.gid+'|'+h.id));

      return {groupsF, mansF, matsF, fVars};
    }

    // Tabelle
    const tbody = document.querySelector('#posTable tbody');
    const addBtn = document.getElementById('addRowBtn');

    function renumber(){
      [...tbody.querySelectorAll('tr')].forEach((tr, i)=>{
        tr.querySelector('.pos-cell').textContent = (i+1);
        tr.querySelectorAll('select, input').forEach(inp=>{
          const name = inp.getAttribute('name'); if (!name) return;
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

      let selG, selH, selM, selV, inpQ;

      function buildInitial(){
        if (TYP==='R' && currentEmployeeId>0) {
          const {groupsF, mansF, matsF, fVars} = filteredCascade();
          selG = buildSelect(groupsF, '— bitte wählen —', pref?.gid || '');
          selH = buildSelect([], '— bitte wählen —', pref?.hid || '');
          selM = buildSelect([], '— bitte wählen —', pref?.mid || '');
          selV = buildSelect([], '— bitte wählen —', pref?.VarianteID || '');
          // attach
          tdG.appendChild(selG); tdH.appendChild(selH); tdM.appendChild(selM); tdV.appendChild(selV);
          function onG(){
            const gid = selG.value;
            const opts = mansF.filter(h=>String(h.gid)===String(gid));
            const n = buildSelect(opts, '— bitte wählen —', pref?.hid || '');
            selH.replaceWith(n); selH=n; selH.name = `pos[${idx}][hid]`; selH.required=true;
            selH.addEventListener('change', onH); onH();
          }
          function onH(){
            const gid = selG.value, hid=selH.value;
            const opts = matsF.filter(m=>String(m.gid)===String(gid)&&String(m.hid)===String(hid));
            const n = buildSelect(opts, '— bitte wählen —', pref?.mid || '');
            selM.replaceWith(n); selM=n; selM.name = `pos[${idx}][mid]`; selM.required=true;
            selM.addEventListener('change', onM); onM();
          }
          function onM(){
            const mid = selM.value;
            const opts = fVars.filter(v=>String(v.mid)===String(mid)).map(v=>{
              const off = offenByVarianteId[Number(v.id)]?.offen ?? null;
              return {id:v.id, name: v.name + (off!=null?` (offen: ${off})`:``)};
            });
            const n = buildSelect(opts, '— bitte wählen —', pref?.VarianteID || '');
            selV.replaceWith(n); selV=n; selV.name = `pos[${idx}][vid]`; selV.required=true;
          }
          selG.name = `pos[${idx}][gid]`; selG.required=true; selG.addEventListener('change', onG); onG();
        } else {
          selG = buildSelect(groups, '— bitte wählen —', pref?.gid || '');
          selH = buildSelect([], '— bitte wählen —', pref?.hid || '');
          selM = buildSelect([], '— bitte wählen —', pref?.mid || '');
          selV = buildSelect([], '— bitte wählen —', pref?.VarianteID || '');
          tdG.appendChild(selG); tdH.appendChild(selH); tdM.appendChild(selM); tdV.appendChild(selV);
          function onG(){
            selH = replaceSel(selH, herstellerByGroup(selG.value), pref?.hid || '', `pos[${idx}][hid]`);
            selH.addEventListener('change', onH); onH();
          }
          function onH(){
            selM = replaceSel(selM, materialsByGH(selG.value, selH.value), pref?.mid || '', `pos[${idx}][mid]`);
            selM.addEventListener('change', onM); onM();
          }
          function onM(){
            selV = replaceSel(selV, variantsByMaterial(selM.value), pref?.VarianteID || '', `pos[${idx}][vid]`);
          }
          selG.name = `pos[${idx}][gid]`; selG.required=true; selG.addEventListener('change', onG); onG();
        }
      }
      function replaceSel(sel, options, value, name){
        const n = buildSelect(options, '— bitte wählen —', value);
        sel.replaceWith(n); n.name = name; n.required=true; return n;
      }

      inpQ = el('input', {type:'number', step:'0.001', min:'0.001', name:`pos[${idx}][qty]`, value:(pref?.Menge || '')});
      if (TYP==='R') {
        // Bei Rückgabe nach Auswahl Variante max = offene Menge setzen (dynamisch)
        // Wird in onM() angezeigt; zum Zeitpunkt des Buildens noch unklar -> Nutzerhinweis via title
        inpQ.title = 'Menge darf offene Menge nicht überschreiten.';
      }

      tdQ.appendChild(inpQ);
      const btnDel = el('button', {type:'button', class:'btn btn-secondary'}, '–');
      btnDel.addEventListener('click', ()=>{ tr.remove(); renumber(); });

      tdX.appendChild(btnDel);
      tr.appendChild(tdPos); tr.appendChild(tdG); tr.appendChild(tdH); tr.appendChild(tdM); tr.appendChild(tdV); tr.appendChild(tdQ); tr.appendChild(tdX);
      tbody.appendChild(tr);
      renumber();

      buildInitial();
    }

    addBtn.addEventListener('click', ()=>addRow());
    if (postedRows && postedRows.length) {
      postedRows.forEach(r=> addRow(r));
    } else {
      addRow();
    }

    // Mitarbeiter-Suche
    const inp = document.getElementById('mit_search');
    const res = document.getElementById('mit_result');
    const hid = document.getElementById('MitarbeiterID');
    const btnClear = document.getElementById('mit_clear');

    let timer=null;
    function search(term){
      fetch(`?p=ausgabe_bestellung_neu&action=employee_search&term=${encodeURIComponent(term)}&typ=${encodeURIComponent(TYP)}`)
        .then(r=>r.json())
        .then(list=>{
          // Dropdown-artige Liste
          res.innerHTML = '';
          if (!Array.isArray(list) || list.length===0) { res.textContent = 'Keine Treffer.'; return; }
          const ul = el('ul', {style:'list-style:none;padding:0;margin:.2rem 0;border:1px solid #e5e7eb;border-radius:8px;max-height:180px;overflow:auto'});
          list.forEach(item=>{
            const li = el('li',{style:'padding:.35rem .5rem;cursor:pointer'}, [
              el('div',{}, item.label),
              el('div',{class:'muted'}, `${item.info.abteilung ?? ''} • ${item.info.typ} • ${item.info.kategorie}`)
            ]);
            li.addEventListener('click', ()=>{
              hid.value = String(item.id);
              currentEmployeeId = Number(item.id);
              res.innerHTML = `Gewählt: <strong>${item.label}</strong>`;
              inp.value = '';
              if (TYP==='R') reloadOffenForEmployee();
            });
            ul.appendChild(li);
          });
          res.appendChild(ul);
        })
        .catch(()=>{ res.textContent='Fehler bei der Suche.'; });
    }
    inp.addEventListener('input', ()=>{
      const t = inp.value.trim();
      if (timer) clearTimeout(timer);
      if (t.length<2) { res.textContent = 'Mind. 2 Zeichen eingeben.'; return; }
      timer = setTimeout(()=>search(t), 250);
    });
    btnClear.addEventListener('click', ()=>{
      hid.value=''; currentEmployeeId=0; inp.value=''; res.textContent='Noch kein Mitarbeiter gewählt.';
      if (TYP==='R') { offenByVarianteId = {}; tbody.innerHTML=''; addRow(); }
    });

    function reloadOffenForEmployee(){
      if (!currentEmployeeId) return;
      fetch(`?p=ausgabe_bestellung_neu&ajax=offen&mid=${currentEmployeeId}`)
        .then(r=>r.json())
        .then(map=>{
          offenByVarianteId = map || {};
          tbody.innerHTML=''; addRow();
        }).catch(()=>{});
    }

    // AJAX für offene Varianten (separater Endpunkt unten)
  })();
  </script>
<?php
layout_footer();

/* ========= AJAX: offene Varianten für Mitarbeiter (Typ R) ========= */
if (($_GET['ajax'] ?? '') === 'offen') {
  header('Content-Type: application/json; charset=utf-8');
  $mid = (int)($_GET['mid'] ?? 0);
  if ($mid<=0) { echo json_encode(new stdClass()); exit; }
  $st = $pdo->prepare("
    SELECT avo.VarianteID, avo.Offen,
           v.MaterialID, m.MaterialgruppeID AS gid, m.HerstellerID AS hid
    FROM dbo.v_Mitarbeiter_AusgabeOffen avo
    JOIN dbo.MatVarianten v ON v.VarianteID = avo.VarianteID
    JOIN dbo.Material m ON m.MaterialID = v.MaterialID
    WHERE avo.MitarbeiterID = :mid
  ");
  $st->execute([':mid'=>$mid]);
  $map = [];
  while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    $map[(int)$r['VarianteID']] = [
      'offen'=>(float)$r['Offen'],
      'mid'=>(int)$r['MaterialID'],
      'gid'=>(int)$r['gid'],
      'hid'=>(int)$r['hid'],
    ];
  }
  echo json_encode($map, JSON_UNESCAPED_UNICODE);
  exit;
}
