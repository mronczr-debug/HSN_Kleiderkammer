<?php
// app/pages/ausgabe_lieferung.php
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

/* ========= Input ========= */
$bestellungId = isset($_GET['bestellung_id']) ? (int)$_GET['bestellung_id'] : 0;
if ($bestellungId<=0) { http_response_code(400); exit('bestellung_id fehlt.'); }

/* ========= Kopf laden ========= */
$k = $pdo->prepare("
  SELECT k.*, ms.Personalnummer, ms.Nachname, ms.Vorname, ms.Abteilung,
         mt.Bezeichnung AS TypName, mk.Bezeichnung AS KatName
  FROM dbo.MitarbeiterBestellungKopf k
  JOIN dbo.MitarbeiterStamm ms ON ms.MitarbeiterID = k.MitarbeiterID
  JOIN dbo.MitarbeiterTyp mt ON mt.TypID = ms.MitarbeiterTypID
  JOIN dbo.MitarbeiterKategorie mk ON mk.KategorieID = ms.MitarbeiterKategorieID
  WHERE k.BestellungID = :id
");
$k->execute([':id'=>$bestellungId]);
$kopf = $k->fetch(PDO::FETCH_ASSOC);
if (!$kopf) { http_response_code(404); exit('Bestellung nicht gefunden.'); }

if ((int)$kopf['Status'] !== 0) {
  // Bereits abgeschlossen -> Anzeige nur
}

/* ========= Lieferung kopf ggf. anlegen (1:1) ========= */
$lstmt = $pdo->prepare("SELECT * FROM dbo.MitarbeiterLieferungKopf WHERE BestellungID = :id");
$lstmt->execute([':id'=>$bestellungId]);
$liefer = $lstmt->fetch(PDO::FETCH_ASSOC);

if (!$liefer) {
  // neu anlegen
  try {
    $pdo->beginTransaction();

    $createdBy = windows_user();

    $proc = $pdo->prepare("DECLARE @nr NVARCHAR(40); EXEC dbo.sp_NextBelegNr @Vorgang=:v, @BelegNr=@nr OUTPUT; SELECT @nr;");
    $proc->execute([':v'=>($kopf['Typ']==='A'?'AL':'RL')]);
    $lieferNr = (string)$proc->fetchColumn();

    $ins = $pdo->prepare("
      INSERT INTO dbo.MitarbeiterLieferungKopf
        (LieferNr, Typ, BestellungID, MitarbeiterID, LieferDatum, Status, RueckgabeGrund, CreatedAt, CreatedBy)
      VALUES
        (:nr, :typ, :bid, :mid, SYSUTCDATETIME(), 0, :grund, SYSUTCDATETIME(), :by);
      SELECT SCOPE_IDENTITY();
    ");
    $ins->execute([
      ':nr'=>$lieferNr, ':typ'=>$kopf['Typ'], ':bid'=>$bestellungId, ':mid'=>$kopf['MitarbeiterID'],
      ':grund'=>($kopf['Typ']==='R' ? ($kopf['RueckgabeGrund'] ?? null) : null),
      ':by'=>$createdBy
    ]);
    $lieferId = (int)$ins->fetchColumn();

    // Positionen aus Bestellung kopieren
    $pdo->prepare("
      INSERT INTO dbo.MitarbeiterLieferungPos (LieferungID, PosNr, VarianteID, Menge)
      SELECT :lid, p.PosNr, p.VarianteID, p.Menge
      FROM dbo.MitarbeiterBestellungPos p
      WHERE p.BestellungID = :bid
    ")->execute([':lid'=>$lieferId, ':bid'=>$bestellungId]);

    $pdo->commit();
    $liefer = $pdo->query("SELECT * FROM dbo.MitarbeiterLieferungKopf WHERE LieferungID = ".$lieferId)->fetch(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500); exit('Lieferung anlegen fehlgeschlagen: '.e($e->getMessage()));
  }
}

/* ========= Positionen laden ========= */
$pos = $pdo->prepare("
  SELECT p.LPosID, p.PosNr, p.VarianteID, p.Menge,
         v.VariantenBezeichnung, m.MaterialName,
         COALESCE(pvt.Farbe,'') AS Farbe, COALESCE(pvt.Groesse,'') AS Groesse
  FROM dbo.MitarbeiterLieferungPos p
  JOIN dbo.MatVarianten v ON v.VarianteID = p.VarianteID
  JOIN dbo.Material m ON m.MaterialID = v.MaterialID
  LEFT JOIN dbo.vVarAttr_Pivot_Arbeitskleidung pvt ON pvt.VarianteID = v.VarianteID
  WHERE p.LieferungID = :lid
  ORDER BY p.PosNr
");
$pos->execute([':lid'=>$liefer['LieferungID']]);
$posRows = $pos->fetchAll(PDO::FETCH_ASSOC);

/* ========= Kaskaden-Daten (für Edit) ========= */
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

/* Für Rückgabe: offene Varianten dieses Mitarbeiters (harte Begrenzung) */
$offenByEmp = [];
if ($kopf['Typ']==='R') {
  $st = $pdo->prepare("
    SELECT avo.VarianteID, avo.Offen,
           v.MaterialID, m.MaterialgruppeID AS gid, m.HerstellerID AS hid
    FROM dbo.v_Mitarbeiter_AusgabeOffen avo
    JOIN dbo.MatVarianten v ON v.VarianteID = avo.VarianteID
    JOIN dbo.Material m ON m.MaterialID = v.MaterialID
    WHERE avo.MitarbeiterID = :mid
  ");
  $st->execute([':mid'=>$kopf['MitarbeiterID']]);
  $off = $st->fetchAll(PDO::FETCH_ASSOC);
  foreach ($off as $x) {
    $offenByEmp[(int)$x['VarianteID']] = [
      'offen'=>(float)$x['Offen'], 'mid'=>(int)$x['MaterialID'],
      'gid'=>(int)$x['gid'], 'hid'=>(int)$x['hid']
    ];
  }
}

/* ========= POST: Positionen ändern, Lieferung löschen, Buchen ========= */
$errors = [];
if (($_POST['action'] ?? '') === 'save_lines') {
  if (!csrf_check($_POST['csrf'] ?? '')) $errors['_']='Sicherheits-Token ungültig.';
  $posIn = $_POST['pos'] ?? []; if (!is_array($posIn)) $posIn=[];
  $clean=[]; $ix=0;
  foreach ($posIn as $i=>$p) {
    $gid=(int)($p['gid']??0); $hid=(int)($p['hid']??0); $mid=(int)($p['mid']??0); $vid=(int)($p['vid']??0);
    $qty=(string)($p['qty']??'');
    if ($gid<=0 && $hid<=0 && $mid<=0 && $vid<=0 && trim($qty)==='') continue;
    if ($gid<=0 || $hid<=0 || $mid<=0 || $vid<=0) { $errors["pos_$i"]='Bitte Kategorie/Hersteller/Artikel/Variante wählen.'; continue; }
    if ($qty==='' || !is_numeric($qty) || (float)$qty<=0) { $errors["pos_$i"]=($errors["pos_$i"]??'').' Menge > 0 erforderlich.'; continue; }
    if ($kopf['Typ']==='R') {
      // Clientseitig gefiltert; serverseitig prüfen (gegen offene Mengen)
      $stmt = $pdo->prepare("
        SELECT avo.Offen FROM dbo.v_Mitarbeiter_AusgabeOffen avo
        WHERE avo.MitarbeiterID = :mid AND avo.VarianteID = :vid
      ");
      $stmt->execute([':mid'=>$kopf['MitarbeiterID'], ':vid'=>$vid]);
      $r = $stmt->fetch(PDO::FETCH_ASSOC);
      if (!$r || (float)$qty > (float)$r['Offen']) { $errors["pos_$i"]='Rückgabemenge überschreitet offene Menge.'; continue; }
    }
    $ix++; $clean[]=['pos'=>$ix,'vid'=>$vid,'qty'=>(float)$qty];
  }
  if (!$clean) $errors['pos']='Mindestens eine Position erfassen.';

  if (!$errors) {
    try {
      $pdo->beginTransaction();
      $pdo->prepare("DELETE FROM dbo.MitarbeiterLieferungPos WHERE LieferungID = :id")->execute([':id'=>$liefer['LieferungID']]);
      $ins = $pdo->prepare("INSERT INTO dbo.MitarbeiterLieferungPos (LieferungID, PosNr, VarianteID, Menge) VALUES (:lid,:pos,:vid,:qty)");
      foreach ($clean as $c) $ins->execute([':lid'=>$liefer['LieferungID'], ':pos'=>$c['pos'], ':vid'=>$c['vid'], ':qty'=>$c['qty']]);
      $pdo->commit();
      flash('success','Positionen gespeichert.');
      header('Location: ?p=ausgabe_lieferung&bestellung_id='.$bestellungId); exit;
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $errors['_'] = 'Speichern fehlgeschlagen: '.$e->getMessage();
    }
  }
}

if (($_POST['action'] ?? '') === 'discard') {
  if (!csrf_check($_POST['csrf'] ?? '')) { $errors['_']='Sicherheits-Token ungültig.'; }
  if (!$errors && (int)$liefer['Status']===0) {
    $pdo->prepare("DELETE FROM dbo.MitarbeiterLieferungKopf WHERE LieferungID = :id")->execute([':id'=>$liefer['LieferungID']]);
    flash('success','Lieferung verworfen.');
    header('Location: ?p=ausgabe'); exit;
  }
}

if (($_POST['action'] ?? '') === 'book') {
  if (!csrf_check($_POST['csrf'] ?? '')) { $errors['_']='Sicherheits-Token ungültig.'; }
  if (!$errors) {
    try {
      if ($kopf['Typ']==='A') {
        $stmt = $pdo->prepare("EXEC dbo.sp_Ausgabe_Buchen @LieferungID = :id");
      } else {
        $stmt = $pdo->prepare("EXEC dbo.sp_Rueckgabe_Buchen @LieferungID = :id");
      }
      $stmt->execute([':id'=>$liefer['LieferungID']]);
      flash('success', ($kopf['Typ']==='A'?'Ausgabe':'Rückgabe').' gebucht. LieferNr: '.$liefer['LieferNr']);
      header('Location: ?p=ausgabe'); exit;
    } catch (Throwable $e) {
      $errors['_'] = 'Buchen fehlgeschlagen: '.$e->getMessage();
    }
  }
}

/* ========= UI ========= */
$title = ($kopf['Typ']==='A'?'Lieferung (Ausgabe)':'Rückgabe (Lieferung)');
require __DIR__.'/../layout.php';
layout_header($title);
?>
  <div class="card">
    <h1><?= e($title) ?></h1>
    <?php if (!empty($errors['_'])): ?><div class="alert alert-error"><?= e($errors['_']) ?></div><?php endif; ?>

    <div class="grid2">
      <div>
        <div class="muted">BestellNr</div>
        <div><strong><?= e($kopf['BestellNr']) ?></strong></div>
      </div>
      <div>
        <div class="muted">LieferNr</div>
        <div><strong><?= e($liefer['LieferNr']) ?></strong></div>
      </div>
      <div>
        <div class="muted">Mitarbeiter</div>
        <div><strong><?= e($kopf['Nachname'].', '.$kopf['Vorname'].' ('.$kopf['Personalnummer'].')') ?></strong></div>
        <div class="muted"><?= e(($kopf['Abteilung']??'').' • '.$kopf['TypName'].' • '.$kopf['KatName']) ?></div>
      </div>
      <div>
        <div class="muted">Status Bestellung</div>
        <div><?= (int)$kopf['Status']===0 ? '<span class="pill">Erfasst</span>' : '<span class="pill on">Abgeschlossen</span>' ?></div>
      </div>
      <?php if ($kopf['Typ']==='R'): ?>
        <div class="grid1" style="grid-column:1/-1">
          <label>Rückgabegrund</label>
          <div class="muted"><?= e($kopf['RueckgabeGrund'] ?? '') ?></div>
        </div>
      <?php endif; ?>
    </div>

    <h2 style="margin-top:1rem">Positionen</h2>
    <?php if (!empty($errors['pos'])): ?><div class="alert alert-error"><?= e($errors['pos']) ?></div><?php endif; ?>

    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="save_lines">

      <table id="posTable">
        <thead>
          <tr>
            <th style="width:70px">Pos.</th>
            <th>Kategorie</th>
            <th>Hersteller</th>
            <th>Artikel</th>
            <th>Variante</th>
            <th style="width:140px">Menge</th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>

      <div style="margin-top:.6rem;display:flex;gap:8px">
        <?php if ((int)$liefer['Status']===0): ?>
          <button class="btn btn-secondary" type="button" id="addRowBtn">+ Position</button>
          <button class="btn btn-primary" type="submit">Speichern</button>
          <button class="btn btn-secondary" type="submit" name="action" value="discard">Verwerfen</button>
        <?php else: ?>
          <span class="btn btn-secondary" style="opacity:.6;pointer-events:none">Änderungen nicht möglich</span>
        <?php endif; ?>
      </div>
    </form>

    <div style="margin-top:10px;display:flex;gap:8px">
      <?php if ((int)$liefer['Status']===0): ?>
        <form method="post" onsubmit="return confirm('Jetzt buchen?');">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="book">
          <button class="btn btn-primary" type="submit"><?= $kopf['Typ']==='A'?'Ausgabe buchen':'Rückgabe buchen' ?></button>
        </form>
        <button class="btn btn-secondary" type="button" title="Noch ohne Funktion">Unterschrift erfassen</button>
      <?php else: ?>
        <span class="btn btn-secondary" style="opacity:.6;pointer-events:none">Bereits gebucht</span>
      <?php endif; ?>
      <?php if (!empty($liefer['PdfPath'])): ?>
        <a class="btn btn-secondary" target="_blank" href="<?= e($liefer['PdfPath']) ?>">PDF Ausgabebeleg</a>
      <?php else: ?>
        <span class="btn btn-secondary" style="opacity:.6;pointer-events:none">PDF Ausgabebeleg</span>
      <?php endif; ?>
      <a class="btn btn-secondary" href="?p=ausgabe">Zurück</a>
    </div>
  </div>

  <script>
  (function(){
    const TYP = <?= json_encode($kopf['Typ']) ?>;
    const groups = <?= json_encode($gruppen, JSON_UNESCAPED_UNICODE) ?>;
    const mats = <?= json_encode($materialRaw, JSON_UNESCAPED_UNICODE) ?>;
    const mans = <?= json_encode($herstellerRaw, JSON_UNESCAPED_UNICODE) ?>;
    const varsAll = <?= json_encode($variantenRaw, JSON_UNESCAPED_UNICODE) ?>;
    const offenMap = <?= json_encode($offenByEmp, JSON_UNESCAPED_UNICODE) ?>;
    const posted = <?= json_encode($posRows, JSON_UNESCAPED_UNICODE) ?>;
    const editable = <?= (int)$liefer['Status']===0 ? 'true':'false' ?>;

    const tbody = document.querySelector('#posTable tbody');
    const addBtn = document.getElementById('addRowBtn');
    if (!editable && addBtn) addBtn.style.display='none';

    function el(tag, attrs={}, children=[]){
      const e = document.createElement(tag);
      for (const [k,v] of Object.entries(attrs)) {
        if (k==='class') e.className = v;
        else if (k==='value') e.value = v;
        else e.setAttribute(k, v);
      }
      (Array.isArray(children)?children:[children]).forEach(c=>{ if(c!==null&&c!==undefined) e.appendChild(typeof c==='string'?document.createTextNode(c):c); });
      return e;
    }
    function buildSelect(options, placeholder='— bitte wählen —', value=''){
      const sel = el('select', editable?{}:{disabled:true});
      sel.appendChild(el('option', {value:''}, placeholder));
      options.forEach(o=>{
        const opt = el('option', {value:String(o.id)}, o.name ?? o.label ?? String(o.id));
        if (String(value)===String(o.id)) opt.selected = true;
        sel.appendChild(opt);
      });
      return sel;
    }
    function herstellerByGroup(gid){ return mans.filter(x=>String(x.gid)===String(gid)); }
    function materialsByGH(gid, hid){ return mats.filter(x=>String(x.gid)===String(gid) && String(x.hid)===String(hid)); }
    function variantsByMaterial(mid){ return varsAll.filter(x=>String(x.mid)===String(mid)); }

    function filteredCascade(){
      const vids = Object.keys(offenMap).map(Number);
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
      const tdG = el('td'), tdH=el('td'), tdM=el('td'), tdV=el('td'), tdQ=el('td');

      let selG, selH, selM, selV, inpQ;
      if (TYP==='R') {
        const {groupsF, mansF, matsF, fVars} = filteredCascade();
        selG = buildSelect(groupsF, '— bitte wählen —', pref?.gid || '');
        selH = buildSelect([], '— bitte wählen —', pref?.hid || '');
        selM = buildSelect([], '— bitte wählen —', pref?.MaterialID || '');
        selV = buildSelect([], '— bitte wählen —', pref?.VarianteID || '');
        tdG.appendChild(selG); tdH.appendChild(selH); tdM.appendChild(selM); tdV.appendChild(selV);
        function onG(){
          selH = replaceSel(selH, mansF.filter(h=>String(h.gid)===String(selG.value)), pref?.hid || '', `pos[${idx}][hid]`);
          selH.addEventListener('change', onH); onH();
        }
        function onH(){
          selM = replaceSel(selM, matsF.filter(m=>String(m.gid)===String(selG.value) && String(m.hid)===String(selH.value)), pref?.MaterialID || '', `pos[${idx}][mid]`);
          selM.addEventListener('change', onM); onM();
        }
        function onM(){
          const opts = fVars.filter(v=>String(v.mid)===String(selM.value)).map(v=>{
            const off = offenMap[Number(v.id)]?.offen ?? null;
            return {id:v.id, name: v.name + (off!=null?` (offen: ${off})`:``)};
          });
          selV = replaceSel(selV, opts, pref?.VarianteID || '', `pos[${idx}][vid]`);
        }
        selG.name = `pos[${idx}][gid]`; selG.required=true; selG.addEventListener('change', onG); onG();
      } else {
        selG = buildSelect(groups, '— bitte wählen —', pref?.gid || '');
        selH = buildSelect([], '— bitte wählen —', pref?.hid || '');
        selM = buildSelect([], '— bitte wählen —', pref?.MaterialID || '');
        selV = buildSelect([], '— bitte wählen —', pref?.VarianteID || '');
        tdG.appendChild(selG); tdH.appendChild(selH); tdM.appendChild(selM); tdV.appendChild(selV);
        function onG(){
          selH = replaceSel(selH, herstellerByGroup(selG.value), pref?.hid || '', `pos[${idx}][hid]`);
          selH.addEventListener('change', onH); onH();
        }
        function onH(){
          selM = replaceSel(selM, materialsByGH(selG.value, selH.value), pref?.MaterialID || '', `pos[${idx}][mid]`);
          selM.addEventListener('change', onM); onM();
        }
        function onM(){
          selV = replaceSel(selV, variantsByMaterial(selM.value), pref?.VarianteID || '', `pos[${idx}][vid]`);
        }
        selG.name = `pos[${idx}][gid]`; selG.required=true; selG.addEventListener('change', onG); onG();
      }

      inpQ = el('input', {type:'number', step:'0.001', min:'0.001', name:`pos[${idx}][qty]`, value:(pref?.Menge || '')}, []);
      if (!editable) inpQ.setAttribute('disabled','true');
      tdQ.appendChild(inpQ);

      tr.appendChild(tdPos); tr.appendChild(tdG); tr.appendChild(tdH); tr.appendChild(tdM); tr.appendChild(tdV); tr.appendChild(tdQ);
      tbody.appendChild(tr); renumber();
    }

    function replaceSel(sel, options, value, name){
      const n = buildSelect(options, '— bitte wählen —', value);
      sel.replaceWith(n); n.name = name; n.required=true; return n;
    }

    // initial Rows
    if (posted && posted.length) {
      posted.forEach(r=> addRow(r));
    } else {
      addRow();
    }

    const addBtn = document.getElementById('addRowBtn');
    if (addBtn) addBtn.addEventListener('click', ()=>addRow());
  })();
  </script>
<?php
layout_footer();
