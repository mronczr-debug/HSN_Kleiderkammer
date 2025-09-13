<?php
// app/pages/ausgabe.php
declare(strict_types=1);
session_start();

/* ========= Minimal-Helpers (keine Redeklaration) ========= */
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
    foreach ($candidates as $u) {
      $u = trim((string)$u);
      if ($u !== '') return mb_substr($u, 0, 256);
    }
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

/* ========= Filter ========= */
$typ     = $_GET['typ']     ?? ''; // '', 'A', 'R'
$status  = $_GET['status']  ?? ''; // '', '0','2'
$q       = trim((string)($_GET['q'] ?? ''));
$page    = max(1, (int)($_GET['page'] ?? 1));
$pageSz  = 15;
$offset  = ($page-1)*$pageSz;

$where = [];
$params = [];
if ($typ === 'A' || $typ === 'R') { $where[] = 'k.Typ = :typ'; $params[':typ']=$typ; }
if ($status === '0' || $status === '2') { $where[] = 'k.Status = :st'; $params[':st'] = (int)$status; }
if ($q !== '') {
  $where[] = "(ms.Personalnummer LIKE :q OR ms.Nachname LIKE :q OR ms.Vorname LIKE :q OR k.BestellNr LIKE :q)";
  $params[':q'] = '%'.$q.'%';
}
$whereSql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

/* ========= Count ========= */
$stmtCnt = $pdo->prepare("
  SELECT COUNT(*) AS c
  FROM dbo.MitarbeiterBestellungKopf k
  JOIN dbo.MitarbeiterStamm ms ON ms.MitarbeiterID = k.MitarbeiterID
  $whereSql
");
$stmtCnt->execute($params);
$total = (int)$stmtCnt->fetchColumn();
$pages = max(1, (int)ceil($total/$pageSz));

/* ========= Rows ========= */
$sql = "
  WITH base AS (
    SELECT
      k.BestellungID, k.BestellNr, k.Typ, k.BestellDatum, k.Status,
      k.MitarbeiterID, ms.Personalnummer,
      CONCAT(ms.Nachname, N', ', ms.Vorname) AS Vollname,
      k.CreatedAt, k.CreatedBy,
      l.LieferungID, l.LieferNr, l.Status AS LieferungStatus, l.PdfPath
    FROM dbo.MitarbeiterBestellungKopf k
    JOIN dbo.MitarbeiterStamm ms ON ms.MitarbeiterID = k.MitarbeiterID
    LEFT JOIN dbo.MitarbeiterLieferungKopf l ON l.BestellungID = k.BestellungID
    $whereSql
  )
  SELECT * FROM base
  ORDER BY BestellDatum DESC, BestellNr DESC
  OFFSET :off ROWS FETCH NEXT :ps ROWS ONLY;
";
$stmt = $pdo->prepare($sql);
foreach ($params as $k=>$v) $stmt->bindValue($k, $v);
$stmt->bindValue(':off', $offset, PDO::PARAM_INT);
$stmt->bindValue(':ps', $pageSz, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$flashSuccess = flash('success');

/* ========= UI ========= */
require __DIR__.'/../layout.php';
layout_header('Ausgabe an Mitarbeiter');
?>
  <div class="card">
    <h1>Ausgabe an Mitarbeiter</h1>

    <?php if ($flashSuccess): ?>
      <div class="alert alert-success"><?= e($flashSuccess) ?></div>
    <?php endif; ?>

    <!-- Top-Aktionen -->
    <div class="grid2" style="margin-bottom:12px">
      <div class="card" style="border:1px dashed #d1d5db">
        <h2 style="margin:0 0 .4rem">Neue Bestellung erfassen</h2>
        <p class="muted">Vorbereitung einer Ausgabe an Mitarbeiter.</p>
        <a class="btn btn-primary" href="?p=ausgabe_bestellung_neu&typ=A">Bestellung (Ausgabe)</a>
      </div>
      <div class="card" style="border:1px dashed #d1d5db">
        <h2 style="margin:0 0 .4rem">Neue Rückgabe erfassen</h2>
        <p class="muted">Vorbereitung einer Rücknahme vom Mitarbeiter.</p>
        <a class="btn btn-primary" href="?p=ausgabe_bestellung_neu&typ=R">Rückgabe (Bestellung)</a>
      </div>
    </div>

    <!-- Filter -->
    <form method="get" class="filters" style="margin-bottom:8px">
      <input type="hidden" name="p" value="ausgabe">
      <div>
        <label for="q">Suche</label>
        <input type="text" id="q" name="q" placeholder="BestellNr, Personalnr, Name..." value="<?= e($q) ?>">
      </div>
      <div>
        <label for="typ">Typ</label>
        <select id="typ" name="typ">
          <option value="">— alle —</option>
          <option value="A" <?= $typ==='A'?'selected':'' ?>>Ausgabe</option>
          <option value="R" <?= $typ==='R'?'selected':'' ?>>Rückgabe</option>
        </select>
      </div>
      <div>
        <label for="status">Status</label>
        <select id="status" name="status">
          <option value="">— alle —</option>
          <option value="0" <?= $status==='0'?'selected':'' ?>>Erfasst</option>
          <option value="2" <?= $status==='2'?'selected':'' ?>>Abgeschlossen</option>
        </select>
      </div>
      <div style="display:flex;gap:8px;align-items:flex-end">
        <button class="btn btn-primary" type="submit">Filtern</button>
        <a class="btn btn-secondary" href="?p=ausgabe">Zurücksetzen</a>
      </div>
    </form>

    <div class="hint"><?= (int)$total ?> Treffer • Seite <?= (int)$page ?> von <?= (int)$pages ?></div>

    <table>
      <thead>
        <tr>
          <th>BestellNr</th>
          <th>Typ</th>
          <th>Mitarbeiter</th>
          <th>Datum</th>
          <th>Status</th>
          <th>Erstellt von</th>
          <th style="width:320px">Aktionen</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($rows)): ?>
          <tr><td colspan="7" class="muted">Keine Bestellungen gefunden.</td></tr>
        <?php else: foreach ($rows as $r): ?>
          <tr>
            <td><strong><?= e($r['BestellNr']) ?></strong></td>
            <td><?= $r['Typ']==='A' ? 'Ausgabe' : 'Rückgabe' ?></td>
            <td>
              <div><?= e($r['Vollname']) ?></div>
              <div class="muted"><?= e($r['Personalnummer']) ?></div>
            </td>
            <td><?= e(substr((string)$r['BestellDatum'], 0, 10)) ?></td>
            <td><?= $r['Status']==2 ? '<span class="pill on">Abgeschlossen</span>' : '<span class="pill">Erfasst</span>' ?></td>
            <td><?= e($r['CreatedBy'] ?? '') ?></td>
            <td style="display:flex;gap:6px;flex-wrap:wrap">
              <?php if ((int)$r['Status']===0): ?>
                <a class="btn btn-secondary" href="?p=ausgabe_bestellung_neu&edit=<?= (int)$r['BestellungID'] ?>">Bearbeiten</a>
                <?php if ($r['Typ']==='A'): ?>
                  <a class="btn btn-primary" href="?p=ausgabe_lieferung&bestellung_id=<?= (int)$r['BestellungID'] ?>">Lieferung erstellen</a>
                <?php else: ?>
                  <a class="btn btn-primary" href="?p=ausgabe_lieferung&bestellung_id=<?= (int)$r['BestellungID'] ?>">Rückgabe erstellen</a>
                <?php endif; ?>
              <?php else: ?>
                <span class="btn btn-secondary" style="opacity:.6;pointer-events:none">Bearbeiten</span>
                <?php if (!empty($r['PdfPath'])): ?>
                  <a class="btn btn-secondary" target="_blank" href="<?= e($r['PdfPath']) ?>">PDF Ausgabebeleg</a>
                <?php else: ?>
                  <span class="btn btn-secondary" style="opacity:.6;pointer-events:none">PDF Ausgabebeleg</span>
                <?php endif; ?>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>

    <?php if ($pages>1): ?>
      <div class="pagination" style="margin-top:10px">
        <?php
          $qs = function(array $ov=[]) use($typ,$status,$q){ return http_build_query(array_merge(['p'=>'ausgabe','typ'=>$typ,'status'=>$status,'q'=>$q], $ov)); };
        ?>
        <a class="btn btn-secondary" href="?<?= $qs(['page'=>1]) ?>" <?= $page==1?'style="opacity:.5;pointer-events:none"':'' ?>>« Erste</a>
        <a class="btn btn-secondary" href="?<?= $qs(['page'=>max(1,$page-1)]) ?>" <?= $page==1?'style="opacity:.5;pointer-events:none"':'' ?>>‹ Zurück</a>
        <span class="muted">Seite <?= $page ?>/<?= $pages ?></span>
        <a class="btn btn-secondary" href="?<?= $qs(['page'=>min($pages,$page+1)]) ?>" <?= $page==$pages?'style="opacity:.5;pointer-events:none"':'' ?>>Weiter ›</a>
        <a class="btn btn-secondary" href="?<?= $qs(['page'=>$pages]) ?>" <?= $page==$pages?'style="opacity:.5;pointer-events:none"':'' ?>>Letzte »</a>
      </div>
    <?php endif; ?>
  </div>
<?php
layout_footer();
