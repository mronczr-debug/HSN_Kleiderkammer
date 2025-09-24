<?php
declare(strict_types=1);

// Session sicher starten
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

// Includes
require_once __DIR__ . '/../../lib/helpers.php';
require_once __DIR__ . '/../../config/db.php'; // stellt $pdo (PDO) bereit

if (!isset($pdo) || !($pdo instanceof PDO)) {
  http_response_code(500);
  exit('DB-Verbindung nicht verfügbar.');
}

/* ============================================================
   Hilfsfunktionen
   ============================================================ */

function csrf_token_local(): string {
  if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
  }
  return $_SESSION['csrf'];
}
function csrf_check_local(string $t): bool {
  return hash_equals($_SESSION['csrf'] ?? '', $t);
}
function current_login(): ?string {
  foreach (['REMOTE_USER','AUTH_USER','LOGON_USER'] as $k) {
    if (!empty($_SERVER[$k])) return (string)$_SERVER[$k];
  }
  $u = getenv('USERNAME');
  return $u ?: null;
}

/* ============================================================
   AJAX: Mitarbeitersuche
   GET ?p=ausgabe_bestellung_neu&ajax=1&action=search_emp&q=...&onlyActive=1
   ============================================================ */
if (isset($_GET['ajax']) && $_GET['ajax'] === '1' && ($_GET['action'] ?? '') === 'search_emp') {
  header('Content-Type: application/json; charset=utf-8');
  $q          = trim((string)($_GET['q'] ?? ''));
  $onlyActive = ($_GET['onlyActive'] ?? '1') === '1';

  try {
    $where = [];
    $p = [];
    if ($q !== '') {
      // gleiche Suche, aber zwei Parameter-Namen verwenden (pdo_sqlsrv-Restriktion)
      $where[]   = "(Vollname LIKE :qs1 OR Personalnummer LIKE :qs2)";
      $p[':qs1'] = '%'.$q.'%';
      $p[':qs2'] = '%'.$q.'%';
    }
    if ($onlyActive) {
      $where[] = "Aktiv = 1";
    }
    $whereSql = $where ? 'WHERE '.implode(' AND ', $where) : '';

    $sql = "
      SELECT TOP (20)
        MitarbeiterID, Personalnummer, Vollname, Abteilung, MitarbeiterTyp, MitarbeiterKategorie
      FROM dbo.vw_Mitarbeiter_Liste
      $whereSql
      ORDER BY Vollname ASC
    ";
    $st = $pdo->prepare($sql);
    $st->execute($p);
    echo json_encode(['ok'=>true,'data'=>$st->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
  } catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
  }
  exit;
}

/* ============================================================
   AJAX: abhängige Dropdowns (Gruppe -> Hersteller -> Material -> Variante)
   ============================================================ */
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
  header('Content-Type: application/json; charset=utf-8');
  $action = $_GET['action'] ?? '';
  try {
    switch ($action) {
      /* ---------- Materialgruppen ---------- */
      case 'list_groups': {
        $st = $pdo->query("SELECT MaterialgruppeID AS id, Gruppenname AS text FROM dbo.Materialgruppe ORDER BY Gruppenname");
        echo json_encode(['ok'=>true,'data'=>$st->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
        break;
      }

      /* ---------- Hersteller (LEFT JOIN; inkl. „ohne Hersteller“) ---------- */
      case 'list_manu': {
        $gid = isset($_GET['gid']) ? (int)$_GET['gid'] : 0;
        $gidParam = $gid > 0 ? $gid : null;

        $sql = "
          SELECT DISTINCT
            ISNULL(h.HerstellerID, 0)          AS id,
            ISNULL(h.Name, N'ohne Hersteller') AS text
          FROM dbo.Material m
          LEFT JOIN dbo.Hersteller h
            ON h.HerstellerID = m.HerstellerID
          WHERE (m.MaterialgruppeID = COALESCE(:gid1, m.MaterialgruppeID))
          ORDER BY text
        ";
        $st = $pdo->prepare($sql);
        if ($gidParam === null) {
          $st->bindValue(':gid1', null, PDO::PARAM_NULL);
        } else {
          $st->bindValue(':gid1', $gidParam, PDO::PARAM_INT);
        }
        $st->execute();
        echo json_encode(['ok'=>true,'data'=>$st->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
        break;
      }

      /* ---------- Materialien (Filter: Gruppe + Hersteller inkl. NULL) ---------- */
      case 'list_mat': {
        $gid = isset($_GET['gid']) ? (int)$_GET['gid'] : 0;
        $hid = isset($_GET['hid']) ? (int)$_GET['hid'] : -99999; // -99999 = „none“ (kein Filter)

        $gidParam = $gid > 0 ? $gid : null;

        // drei Modi: none / null / id
        $hidMode = 'none';
        $hidVal  = null;
        if ($hid === 0) {           // 0 steht für „ohne Hersteller“
          $hidMode = 'null';
        } elseif ($hid > 0) {       // konkreter Hersteller
          $hidMode = 'id';
          $hidVal  = $hid;
        }

        $sql = "
          SELECT m.MaterialID AS id, m.MaterialName AS text
          FROM dbo.Material m
          WHERE m.IsActive = 1
            AND (m.MaterialgruppeID = COALESCE(:gid1, m.MaterialgruppeID))
            AND (
                 (:hidMode1 = 'none')
              OR (:hidMode2 = 'null' AND m.HerstellerID IS NULL)
              OR (:hidMode3 = 'id'   AND m.HerstellerID = :hidVal1)
            )
          ORDER BY m.MaterialName
        ";
        $st = $pdo->prepare($sql);

        // :gid1
        if ($gidParam === null) {
          $st->bindValue(':gid1', null, PDO::PARAM_NULL);
        } else {
          $st->bindValue(':gid1', $gidParam, PDO::PARAM_INT);
        }
        // :hidMode1/2/3 getrennt binden (pdo_sqlsrv möchte Parameter nicht mehrfach)
        $st->bindValue(':hidMode1', $hidMode, PDO::PARAM_STR);
        $st->bindValue(':hidMode2', $hidMode, PDO::PARAM_STR);
        $st->bindValue(':hidMode3', $hidMode, PDO::PARAM_STR);
        // :hidVal1
        if ($hidVal === null) {
          $st->bindValue(':hidVal1', null, PDO::PARAM_NULL);
        } else {
          $st->bindValue(':hidVal1', $hidVal, PDO::PARAM_INT);
        }

        $st->execute();
        echo json_encode(['ok'=>true,'data'=>$st->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
        break;
      }

      /* ---------- Varianten (inkl. Farbe/Größe) ---------- */
      case 'list_var': {
        $mid = isset($_GET['mid']) ? (int)$_GET['mid'] : 0;
        if ($mid <= 0) {
          echo json_encode(['ok'=>true,'data'=>[]]);
          break;
        }
        $sql = "
          SELECT v.VarianteID AS id,
                 v.VariantenBezeichnung AS bez,
                 v.SKU,
                 p.Farbe,
                 p.Groesse
          FROM dbo.MatVarianten v
          LEFT JOIN dbo.vVarAttr_Pivot_Arbeitskleidung p
            ON p.VarianteID = v.VarianteID
          WHERE v.MaterialID = :mid1 AND v.IsActive = 1
          ORDER BY v.VariantenBezeichnung
        ";
        $st = $pdo->prepare($sql);
        $st->bindValue(':mid1', $mid, PDO::PARAM_INT);
        $st->execute();

        $rows = [];
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
          $fg = trim((string)($r['Farbe']   ?? ''));
          $gr = trim((string)($r['Groesse'] ?? ''));
          $parts = [];
          if ($fg !== '') $parts[] = $fg;
          if ($gr !== '') $parts[] = $gr;
          $prefix = $parts ? implode(', ', $parts) . ' — ' : '';
          $rows[] = ['id' => (int)$r['id'], 'text' => $prefix . (string)$r['bez']];
        }
        echo json_encode(['ok'=>true,'data'=>$rows], JSON_UNESCAPED_UNICODE);
        break;
      }

      default:
        echo json_encode(['ok'=>false,'error'=>'Unbekannte Aktion']);
    }
  } catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
  }
  exit;
}

/* ============================================================
   POST: Bestellung (Typ A) mit Positionen anlegen
   ============================================================ */
$errors = [];

if (($_POST['action'] ?? '') === 'create_order') {
  $csrf = $_POST['csrf'] ?? '';
  if (!csrf_check_local($csrf)) {
    $errors['_'] = 'Sicherheits-Token ungültig. Bitte Seite neu laden.';
  } else {
    $mitarbeiterId = (int)($_POST['MitarbeiterID'] ?? 0);
    if ($mitarbeiterId <= 0) {
      $errors['MitarbeiterID'] = 'Bitte einen Mitarbeiter auswählen.';
    } else {
      $chk = $pdo->prepare("SELECT 1 FROM dbo.MitarbeiterStamm WHERE MitarbeiterID = :id1");
      $chk->execute([':id1' => $mitarbeiterId]);
      if (!$chk->fetchColumn()) {
        $errors['MitarbeiterID'] = 'Mitarbeiter nicht gefunden.';
      }
    }

    // Positionswerte
    $pos_variante = $_POST['pos_variante'] ?? [];
    $pos_menge    = $_POST['pos_menge'] ?? [];

    $posCount = 0;
    for ($i=0; $i < max(count($pos_variante), count($pos_menge)); $i++) {
      $vid = isset($pos_variante[$i]) ? (int)$pos_variante[$i] : 0;
      $mengeRaw = trim((string)($pos_menge[$i] ?? ''));
      if ($vid > 0 && $mengeRaw !== '') {
        $menge = (float)str_replace(',', '.', $mengeRaw);
        if ($menge > 0) $posCount++;
      }
    }
    if ($posCount === 0) {
      $errors['pos'] = 'Bitte mindestens eine Position mit Variante und Menge erfassen.';
    }

    if (!$errors) {
      try {
        $pdo->beginTransaction();

        // 1) Bestellnummer ziehen (AB = Ausgabe-Bestellung) – per EXEC + SELECT und nextRowset()
        $stmtNr = $pdo->prepare("
          DECLARE @nr NVARCHAR(40);
          EXEC dbo.sp_NextBelegNr @Vorgang = :v1, @BelegNr = @nr OUTPUT;
          SELECT @nr AS BelegNr;
        ");
        $stmtNr->execute([':v1' => 'AB']);

        // Auf das Resultset mit Spalten springen
        if ($stmtNr->columnCount() === 0) {
          while ($stmtNr->nextRowset()) {
            if ($stmtNr->columnCount() > 0) break;
          }
        }
        $bestellNr = (string)$stmtNr->fetchColumn();
        if ($bestellNr === '' || $bestellNr === null) {
          throw new RuntimeException('Konnte keine Bestellnummer erzeugen.');
        }

        // 2) Kopf anlegen – KEIN OUTPUT (Trigger vorhanden). Stattdessen: SCOPE_IDENTITY holen.
        $sqlInsK = "
          INSERT INTO dbo.MitarbeiterBestellungKopf
            (BestellNr, Typ, MitarbeiterID, BestellDatum, Status, RueckgabeGrund, CreatedAt, CreatedBy)
          VALUES (:bnr1, 'A', :mid1, SYSUTCDATETIME(), 0, NULL, SYSUTCDATETIME(), :cby1);
          SELECT CAST(SCOPE_IDENTITY() AS int) AS NewID;
        ";
        $stK = $pdo->prepare($sqlInsK);
        $stK->execute([
          ':bnr1' => $bestellNr,
          ':mid1' => $mitarbeiterId,
          ':cby1' => current_login()
        ]);
        // Falls der Treiber zuerst ein leeres Rowset liefert, weiterklicken
        if ($stK->columnCount() === 0) {
          while ($stK->nextRowset()) {
            if ($stK->columnCount() > 0) break;
          }
        }
        $bestellungId = (int)$stK->fetchColumn();
        if ($bestellungId <= 0) {
          throw new RuntimeException('Konnte Bestellung-Kopf-ID nicht ermitteln.');
        }

        // 3) Positionen anlegen
        $sqlInsP = "
          INSERT INTO dbo.MitarbeiterBestellungPos (BestellungID, PosNr, VarianteID, Menge)
          VALUES (:bid1, :pos1, :vid1, :menge1);
        ";
        $spi = $pdo->prepare($sqlInsP);

        $posNr = 0;
        for ($i=0; $i < max(count($pos_variante), count($pos_menge)); $i++) {
          $vid = isset($pos_variante[$i]) ? (int)$pos_variante[$i] : 0;
          $mengeRaw = trim((string)($pos_menge[$i] ?? ''));
          if ($vid <= 0 || $mengeRaw === '') continue;

          $menge = (float)str_replace(',', '.', $mengeRaw);
          if ($menge <= 0) continue;

          $chkV = $pdo->prepare("SELECT 1 FROM dbo.MatVarianten WHERE VarianteID = :vid2");
          $chkV->execute([':vid2'=>$vid]);
          if (!$chkV->fetchColumn()) {
            throw new RuntimeException('Ungültige Variante-ID in einer Position.');
          }

          $posNr++;
          $spi->execute([
            ':bid1'   => $bestellungId,
            ':pos1'   => $posNr,
            ':vid1'   => $vid,
            ':menge1' => $menge,
          ]);
        }

        if ($posNr === 0) {
          throw new RuntimeException('Keine gültigen Positionen gefunden.');
        }

        $pdo->commit();

        $_SESSION['flash_success'] = 'Bestellung '.$bestellNr.' wurde angelegt ('.$posNr.' Pos.).';
        header('Location: '.rtrim(base_url(), '/').'/?p=ausgabe');
        exit;
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errors['_'] = 'Speichern fehlgeschlagen: '.$e->getMessage();
      }
    }
  }
}

/* ============================================================
   UI
   ============================================================ */
layout_header('Ausgabe – Neue Bestellung', 'ausgabe');
?>
  <div class="card">
    <h1>Neue Bestellung (Ausgabe)</h1>
    <div class="hint">Wähle den Mitarbeiter und erfasse darunter die Positionen. Die Bestellnummer wird automatisch vergeben.</div>
  </div>

  <div class="split">
    <div class="card">
      <h2>Kopfdaten</h2>

      <?php if (!empty($errors['_'])): ?>
        <div class="alert alert-error"><?= e($errors['_']) ?></div>
      <?php endif; ?>

      <form method="post" id="form-create-order" class="grid1" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= e(csrf_token_local()) ?>">
        <input type="hidden" name="action" value="create_order">
        <input type="hidden" id="emp-id" name="MitarbeiterID" value="">

        <div>
          <label for="emp-search">Mitarbeiter suchen *</label>
          <input type="text" id="emp-search" placeholder="Nachname, Vorname oder Personalnummer">
          <?php if (!empty($errors['MitarbeiterID'])): ?>
            <div class="alert alert-error"><?= e($errors['MitarbeiterID']) ?></div>
          <?php endif; ?>
          <div id="emp-suggest" style="position:relative;margin-top:6px;">
            <div id="emp-suggest-list" style="position:absolute;z-index:10;left:0;right:0;background:#fff;border:1px solid var(--border);border-radius:10px;display:none;max-height:260px;overflow:auto"></div>
          </div>
          <div id="emp-info" class="hint" style="margin-top:10px;display:none"></div>
        </div>

        <h2>Positionsdaten</h2>
        <?php if (!empty($errors['pos'])): ?>
          <div class="alert alert-error"><?= e($errors['pos']) ?></div>
        <?php endif; ?>

        <table id="pos-table">
          <thead>
            <tr>
              <th style="width:4rem;">Pos.</th>
              <th>Kategorie</th>
              <th>Hersteller</th>
              <th>Artikel</th>
              <th>Variante</th>
              <th style="width:9rem;">Menge</th>
              <th style="width:3rem;"></th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>

        <div style="margin-top:8px; display:flex; gap:8px;">
          <button type="button" class="btn btn-secondary" id="btn-add-row">Position hinzufügen</button>
          <button type="submit" class="btn btn-primary">Bestellung speichern</button>
          <a class="btn btn-secondary" href="<?= e(base_url()) ?>/?p=ausgabe">Abbrechen</a>
        </div>
      </form>
    </div>

    <div class="card">
      <h2>Hinweise</h2>
      <ul>
        <li>Bestellnummer per <code>EXEC…; SELECT…;</code> + <code>nextRowset()</code> ausgelesen.</li>
        <li>Kopf-ID per <code>SCOPE_IDENTITY()</code> selektiert (verträglich mit Triggern).</li>
        <li>Alle SQL-Parameter eindeutig benannt (verhindert SQLSTATE[07002]).</li>
      </ul>
    </div>
  </div>

  <script>
    (function(){
      function toast(msg){ console.error(msg); }

      // ---- Mitarbeiter-Suche ----
      const $q   = document.getElementById('emp-search');
      const $hid = document.getElementById('emp-id');
      const $box = document.getElementById('emp-suggest-list');
      const $inf = document.getElementById('emp-info');
      let ctrl = null, blurHideTimer = null;

      function hideList(){ $box.style.display='none'; $box.innerHTML=''; }
      function showList(items){
        $box.innerHTML = '';
        if (!items || items.length===0) {
          const li = document.createElement('div');
          li.className = 'muted'; li.style.padding='.5rem .75rem'; li.textContent = 'Keine Treffer';
          $box.appendChild(li);
        } else {
          items.forEach(r => {
            const row = document.createElement('div');
            row.style.padding='.55rem .75rem';
            row.style.cursor='pointer';
            row.style.display='flex';
            row.style.flexDirection='column';
            row.addEventListener('mouseenter', () => { row.style.background = '#f3f4f6'; });
            row.addEventListener('mouseleave', () => { row.style.background = 'transparent'; });
            row.addEventListener('click', () => {
              $hid.value = String(r.MitarbeiterID);
              $q.value   = (r.Vollname || '') + ' — ' + (r.Personalnummer || '');
              $inf.style.display = 'block';
              $inf.innerHTML = 'Ausgewählt: <strong>' + escapeHtml(r.Vollname||'') + '</strong>'
                            + (r.Abteilung ? ' • Abteilung: ' + escapeHtml(r.Abteilung) : '')
                            + (r.MitarbeiterTyp ? ' • Typ: ' + escapeHtml(r.MitarbeiterTyp) : '')
                            + (r.MitarbeiterKategorie ? ' • Kategorie: ' + escapeHtml(r.MitarbeiterKategorie) : '');
              hideList();
            });
            const line1 = document.createElement('div');
            line1.innerHTML = '<strong>'+escapeHtml(r.Vollname||'')+'</strong>' + (r.Personalnummer ? ' — '+escapeHtml(r.Personalnummer) : '');
            const line2 = document.createElement('div');
            line2.className = 'muted';
            line2.textContent = [r.Abteilung, r.MitarbeiterTyp, r.MitarbeiterKategorie].filter(Boolean).join(' • ');
            row.appendChild(line1); row.appendChild(line2);
            $box.appendChild(row);
          });
        }
        $box.style.display = 'block';
      }
      function searchEmp(){
        const val = $q.value.trim();
        if (ctrl) ctrl.abort();
        ctrl = new AbortController();
        const url = new URL(window.location.href);
        url.searchParams.set('ajax','1');
        url.searchParams.set('action','search_emp');
        url.searchParams.set('q', val);
        url.searchParams.set('onlyActive','1');
        fetch(url.toString(), { signal: ctrl.signal })
          .then(r => r.json())
          .then(j => { if (!j || !j.ok) { toast(j && j.error ? j.error : 'Suche fehlgeschlagen'); showList([]); return; } showList(j.data||[]); })
          .catch(err => { toast(err); });
      }
      let t=null;
      $q.addEventListener('input', () => { $hid.value=''; $inf.style.display='none'; clearTimeout(t); t=setTimeout(searchEmp, 180); });
      $q.addEventListener('focus', () => { clearTimeout(blurHideTimer); if ($box.innerHTML.trim()!=='') $box.style.display='block'; if ($q.value.trim()===''){ clearTimeout(t); t=setTimeout(searchEmp, 10);} });
      $q.addEventListener('blur',  () => { blurHideTimer = setTimeout(hideList, 150); });

      function escapeHtml(s){ return String(s??'').replace(/[&<>"']/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

      // ---- Positions-Tabelle ----
      const $tbody = document.querySelector('#pos-table tbody');
      const $btnAdd = document.getElementById('btn-add-row');

      function api(action, params={}){
        const url = new URL(window.location.href);
        url.searchParams.set('ajax','1');
        url.searchParams.set('action', action);
        Object.entries(params).forEach(([k,v])=>url.searchParams.set(k,String(v)));
        return fetch(url.toString()).then(r=>r.json());
      }

      function option(el, val, txt){
        const o = document.createElement('option');
        o.value = val; o.textContent = txt;
        el.appendChild(o);
      }

      function createRow(){
        const tr = document.createElement('tr');

        const tdPos = document.createElement('td');
        tdPos.className='muted';
        tdPos.style.textAlign='right';
        tdPos.textContent = String($tbody.children.length + 1);

        const tdGrp = document.createElement('td');
        const selGrp = document.createElement('select');

        const tdMan = document.createElement('td');
        const selMan = document.createElement('select');

        const tdMat = document.createElement('td');
        const selMat = document.createElement('select');

        const tdVar = document.createElement('td');
        const selVar = document.createElement('select');
        selVar.name = 'pos_variante[]';

        const tdQty = document.createElement('td');
        const inpQty = document.createElement('input');
        inpQty.type = 'text';
        inpQty.name = 'pos_menge[]';
        inpQty.placeholder = 'Menge';
        inpQty.inputMode = 'decimal';

        const tdDel = document.createElement('td');
        const btnDel = document.createElement('button');
        btnDel.type = 'button';
        btnDel.className = 'btn btn-secondary';
        btnDel.textContent = '–';
        btnDel.title = 'Zeile entfernen';

        [selGrp, selMan, selMat, selVar].forEach(s => {
          s.style.width='100%';
          s.innerHTML='';
          option(s, '', '— bitte wählen —');
        });

        tdGrp.appendChild(selGrp);
        tdMan.appendChild(selMan);
        tdMat.appendChild(selMat);
        tdVar.appendChild(selVar);
        tdQty.appendChild(inpQty);
        tdDel.appendChild(btnDel);

        tr.appendChild(tdPos);
        tr.appendChild(tdGrp);
        tr.appendChild(tdMan);
        tr.appendChild(tdMat);
        tr.appendChild(tdVar);
        tr.appendChild(tdQty);
        tr.appendChild(tdDel);

        // Laden: Gruppen
        api('list_groups').then(j=>{
          if (j && j.ok) {
            j.data.forEach(r=>option(selGrp, r.id, r.text));
          } else {
            toast(j && j.error ? j.error : 'Fehler beim Laden der Gruppen');
          }
        }).catch(err=>toast(err));

        // Events
        selGrp.addEventListener('change', ()=>{
          selMan.innerHTML=''; option(selMan,'','— bitte wählen —');
          selMat.innerHTML=''; option(selMat,'','— bitte wählen —');
          selVar.innerHTML=''; option(selVar,'','— bitte wählen —');

          const gid = parseInt(selGrp.value||'0',10);
          api('list_manu', {gid: isNaN(gid)?0:gid})
            .then(j=>{
              if (j && j.ok) { j.data.forEach(r=>option(selMan, r.id, r.text)); }
              else { toast(j && j.error ? j.error : 'Fehler beim Laden der Hersteller'); }
            })
            .catch(err=>toast(err));
        });

        selMan.addEventListener('change', ()=>{
          selMat.innerHTML=''; option(selMat,'','— bitte wählen —');
          selVar.innerHTML=''; option(selVar,'','— bitte wählen —');
          const gid = parseInt(selGrp.value||'0',10);
          const hid = parseInt(selMan.value||'0',10);
          api('list_mat', {gid: isNaN(gid)?0:gid, hid: isNaN(hid)?-99999:hid})
            .then(j=>{
              if (j && j.ok) { j.data.forEach(r=>option(selMat, r.id, r.text)); }
              else { toast(j && j.error ? j.error : 'Fehler beim Laden der Artikel'); }
            })
            .catch(err=>toast(err));
        });

        selMat.addEventListener('change', ()=>{
          selVar.innerHTML=''; option(selVar,'','— bitte wählen —');
          const mid = parseInt(selMat.value||'0',10);
          if (!isNaN(mid) && mid>0) {
            api('list_var', {mid})
              .then(j=>{
                if (j && j.ok) { j.data.forEach(r=>option(selVar, r.id, r.text)); }
                else { toast(j && j.error ? j.error : 'Fehler beim Laden der Varianten'); }
              })
              .catch(err=>toast(err));
          }
        });

        btnDel.addEventListener('click', ()=>{
          tr.remove();
          Array.from($tbody.children).forEach((row, idx) => {
            row.firstChild.textContent = String(idx+1);
          });
        });

        $tbody.appendChild(tr);
      }

      document.getElementById('btn-add-row').addEventListener('click', createRow);

      // mindestens eine Zeile initial
      createRow();
      createRow(); // optional zweite
    })();
  </script>

<?php layout_footer();
