<?php
declare(strict_types=1);

/**
 * Bestände – reine Bestandsauskunft
 * Orientiert an Layout der Seiten „Materialien“/„Mitarbeiter“.
 *
 * Filter:
 * - q: Volltext (Material, Variante, SKU, Farbe, Größe)
 * - grp: MaterialgruppeID
 * - man: HerstellerID
 * - loc: LagerortID (optional)
 * - grpby: 'var' (Variante, Standard) | 'mat' (Material)
 * - zero: '1' = auch Nullbestände zeigen, '0' (Default) = nur ≠ 0
 * - sort: 'name' (Default) | 'bestand_desc'
 * - page: Pagination
 *
 * Erwartet: $pdo (aus config/db.php), Helpers (e, base_url)
 */

$base = base_url();

/* ========= Lookups ========= */
$hersteller = $pdo->query("SELECT HerstellerID, Name FROM dbo.Hersteller ORDER BY Name")->fetchAll(PDO::FETCH_ASSOC);
$gruppen    = $pdo->query("SELECT MaterialgruppeID, Gruppenname FROM dbo.Materialgruppe ORDER BY Gruppenname")->fetchAll(PDO::FETCH_ASSOC);
$lagerorte  = $pdo->query("SELECT LagerortID, Code, Bezeichnung FROM dbo.Lagerort WHERE IsActive = 1 ORDER BY Code")->fetchAll(PDO::FETCH_ASSOC);

/* ========= Input ========= */
$q        = trim((string)($_GET['q'] ?? ''));
$grp      = $_GET['grp'] ?? '';
$man      = $_GET['man'] ?? '';
$loc      = $_GET['loc'] ?? ''; // LagerortID
$grpby    = ($_GET['grpby'] ?? 'var') === 'mat' ? 'mat' : 'var';
$zero     = ($_GET['zero'] ?? '0') === '1' ? '1' : '0';
$sort     = ($_GET['sort'] ?? 'name') === 'bestand_desc' ? 'bestand_desc' : 'name';
$page     = max(1, (int)($_GET['page'] ?? 1));
$pageSize = 15;
$offset   = ($page - 1) * $pageSize;

/* ========= Common WHERE for meta filters ========= */
$whereMeta = [];
$paramsMeta = [];

if ($grp !== '' && ctype_digit((string)$grp)) {
  $whereMeta[] = "m.MaterialgruppeID = :grp";
  $paramsMeta[':grp'] = (int)$grp;
}
if ($man !== '' && ctype_digit((string)$man)) {
  $whereMeta[] = "m.HerstellerID = :man";
  $paramsMeta[':man'] = (int)$man;
}
$whereMetaSQL = $whereMeta ? (' AND '.implode(' AND ', $whereMeta)) : '';

/* ========= Parameter-Aufbereitung (einmalig binden) ========= */
$pattern = ($q === '') ? null : ('%'.$q.'%');
$locVal  = ($loc !== '' && ctype_digit((string)$loc)) ? (int)$loc : null;

/* ========= Query-Builder ========= */
if ($grpby === 'var') {
  /* ---------- Variante: Aggregation (optional nach Lagerort) ---------- */

  $sqlCount = "
    WITH par AS (
      SELECT CAST(:loc AS INT) AS loc, CAST(:qs AS NVARCHAR(200)) AS qs
    ),
    agg AS (
      SELECT
        dv.VarianteID,
        CASE WHEN par.loc IS NULL THEN NULL ELSE dv.LagerortID END AS LagerortID,
        SUM(dv.Bestand) AS Bestand
      FROM dbo.vw_Bestand_AktiveVarianten_Detail dv
      CROSS JOIN par
      WHERE (par.loc IS NULL OR dv.LagerortID = par.loc)
      GROUP BY dv.VarianteID, CASE WHEN par.loc IS NULL THEN NULL ELSE dv.LagerortID END
    )
    SELECT COUNT(*) AS cnt
    FROM dbo.MatVarianten v
    JOIN dbo.Material m ON m.MaterialID = v.MaterialID
    LEFT JOIN dbo.vVarAttr_Pivot_Arbeitskleidung pvt ON pvt.VarianteID = v.VarianteID
    LEFT JOIN agg a ON a.VarianteID = v.VarianteID
    CROSS JOIN par
    WHERE v.IsActive = 1
      AND (par.qs IS NULL OR (
            m.MaterialName LIKE par.qs
         OR v.VariantenBezeichnung LIKE par.qs
         OR ISNULL(v.SKU,'') LIKE par.qs
         OR ISNULL(pvt.Farbe,'') LIKE par.qs
         OR ISNULL(pvt.Groesse,'') LIKE par.qs
      ))
      ".($zero==='1' ? "" : " AND COALESCE(a.Bestand,0) <> 0 ")."
      $whereMetaSQL
  ";

  $sqlRows = "
    WITH par AS (
      SELECT CAST(:loc AS INT) AS loc, CAST(:qs AS NVARCHAR(200)) AS qs
    ),
    agg AS (
      SELECT
        dv.VarianteID,
        CASE WHEN par.loc IS NULL THEN NULL ELSE dv.LagerortID END AS LagerortID,
        SUM(dv.Bestand) AS Bestand
      FROM dbo.vw_Bestand_AktiveVarianten_Detail dv
      CROSS JOIN par
      WHERE (par.loc IS NULL OR dv.LagerortID = par.loc)
      GROUP BY dv.VarianteID, CASE WHEN par.loc IS NULL THEN NULL ELSE dv.LagerortID END
    )
    SELECT
      v.VarianteID,
      m.MaterialID,
      m.MaterialName,
      g.Gruppenname       AS Materialgruppe,
      h.Name              AS HerstellerName,
      v.VariantenBezeichnung,
      v.SKU,
      pvt.Farbe,
      pvt.Groesse,
      a.LagerortID,
      COALESCE(a.Bestand,0) AS Bestand
    FROM dbo.MatVarianten v
    JOIN dbo.Material m       ON m.MaterialID = v.MaterialID
    LEFT JOIN dbo.Materialgruppe g ON g.MaterialgruppeID = m.MaterialgruppeID
    LEFT JOIN dbo.Hersteller h     ON h.HerstellerID = m.HerstellerID
    LEFT JOIN dbo.vVarAttr_Pivot_Arbeitskleidung pvt ON pvt.VarianteID = v.VarianteID
    LEFT JOIN agg a ON a.VarianteID = v.VarianteID
    CROSS JOIN par
    WHERE v.IsActive = 1
      AND (par.qs IS NULL OR (
            m.MaterialName LIKE par.qs
         OR v.VariantenBezeichnung LIKE par.qs
         OR ISNULL(v.SKU,'') LIKE par.qs
         OR ISNULL(pvt.Farbe,'') LIKE par.qs
         OR ISNULL(pvt.Groesse,'') LIKE par.qs
      ))
      ".($zero==='1' ? "" : " AND COALESCE(a.Bestand,0) <> 0 ")."
      $whereMetaSQL
    ORDER BY
      ".($sort==='bestand_desc' ? "COALESCE(a.Bestand,0) DESC, m.MaterialName ASC, v.VariantenBezeichnung ASC"
                                : "m.MaterialName ASC, v.VariantenBezeichnung ASC")."
    OFFSET :off ROWS FETCH NEXT :ps ROWS ONLY
  ";

} else {
  /* ---------- Material: Aggregation über Varianten (optional nach Lagerort) ---------- */

  $sqlCount = "
    WITH par AS (
      SELECT CAST(:loc AS INT) AS loc, CAST(:qs AS NVARCHAR(200)) AS qs
    ),
    agg AS (
      SELECT
        dv.VarianteID,
        CASE WHEN par.loc IS NULL THEN NULL ELSE dv.LagerortID END AS LagerortID,
        SUM(dv.Bestand) AS Bestand
      FROM dbo.vw_Bestand_AktiveVarianten_Detail dv
      CROSS JOIN par
      WHERE (par.loc IS NULL OR dv.LagerortID = par.loc)
      GROUP BY dv.VarianteID, CASE WHEN par.loc IS NULL THEN NULL ELSE dv.LagerortID END
    )
    SELECT COUNT(*) AS cnt
    FROM dbo.Material m
    CROSS JOIN par
    WHERE EXISTS (
      SELECT 1
      FROM dbo.MatVarianten v
      LEFT JOIN agg a ON a.VarianteID = v.VarianteID
      WHERE v.MaterialID = m.MaterialID
        AND v.IsActive = 1
        ".($zero==='1' ? "" : " AND COALESCE(a.Bestand,0) <> 0 ")."
    )
    AND (par.qs IS NULL OR m.MaterialName LIKE par.qs)
    $whereMetaSQL
  ";

  $sqlRows = "
    WITH par AS (
      SELECT CAST(:loc AS INT) AS loc, CAST(:qs AS NVARCHAR(200)) AS qs
    ),
    agg AS (
      SELECT
        dv.VarianteID,
        CASE WHEN par.loc IS NULL THEN NULL ELSE dv.LagerortID END AS LagerortID,
        SUM(dv.Bestand) AS Bestand
      FROM dbo.vw_Bestand_AktiveVarianten_Detail dv
      CROSS JOIN par
      WHERE (par.loc IS NULL OR dv.LagerortID = par.loc)
      GROUP BY dv.VarianteID, CASE WHEN par.loc IS NULL THEN NULL ELSE dv.LagerortID END
    )
    SELECT
      m.MaterialID,
      m.MaterialName,
      g.Gruppenname       AS Materialgruppe,
      h.Name              AS HerstellerName,
      SUM(COALESCE(a.Bestand,0)) AS Bestand
    FROM dbo.Material m
    LEFT JOIN dbo.Materialgruppe g ON g.MaterialgruppeID = m.MaterialgruppeID
    LEFT JOIN dbo.Hersteller h     ON h.HerstellerID    = m.HerstellerID
    JOIN dbo.MatVarianten v        ON v.MaterialID = m.MaterialID AND v.IsActive = 1
    LEFT JOIN agg a                ON a.VarianteID = v.VarianteID
    CROSS JOIN par
    WHERE (par.qs IS NULL OR m.MaterialName LIKE par.qs)
      $whereMetaSQL
      ".($zero==='1' ? "" : " AND COALESCE(a.Bestand,0) <> 0 ")."
    GROUP BY m.MaterialID, m.MaterialName, g.Gruppenname, h.Name
    ORDER BY
      ".($sort==='bestand_desc' ? "SUM(COALESCE(a.Bestand,0)) DESC, m.MaterialName ASC"
                                : "m.MaterialName ASC")."
    OFFSET :off ROWS FETCH NEXT :ps ROWS ONLY
  ";
}

/* ========= Total Count ========= */
$stmtCnt = $pdo->prepare($sqlCount);
/* nur die tatsächlich im Statement vorkommenden Parameter binden */
$stmtCnt->bindValue(':loc', $locVal, $locVal === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
$stmtCnt->bindValue(':qs',  $pattern, $pattern === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
if (array_key_exists(':grp', $paramsMeta)) $stmtCnt->bindValue(':grp', $paramsMeta[':grp'], PDO::PARAM_INT);
if (array_key_exists(':man', $paramsMeta)) $stmtCnt->bindValue(':man', $paramsMeta[':man'], PDO::PARAM_INT);
$stmtCnt->execute();
$total = (int)$stmtCnt->fetch(PDO::FETCH_ASSOC)['cnt'];
$pages = max(1, (int)ceil($total / $pageSize));

/* ========= Rows ========= */
$stmt = $pdo->prepare($sqlRows);
$stmt->bindValue(':loc', $locVal, $locVal === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
$stmt->bindValue(':qs',  $pattern, $pattern === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
if (array_key_exists(':grp', $paramsMeta)) $stmt->bindValue(':grp', $paramsMeta[':grp'], PDO::PARAM_INT);
if (array_key_exists(':man', $paramsMeta)) $stmt->bindValue(':man', $paramsMeta[':man'], PDO::PARAM_INT);
$stmt->bindValue(':off', $offset, PDO::PARAM_INT);
$stmt->bindValue(':ps',  $pageSize, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ========= UI ========= */
$qs = function(array $overrides=[]) use($q,$grp,$man,$loc,$grpby,$zero,$sort,$page){
  return http_build_query(array_merge([
    'p'=>'bestaende','q'=>$q,'grp'=>$grp,'man'=>$man,'loc'=>$loc,
    'grpby'=>$grpby,'zero'=>$zero,'sort'=>$sort,'page'=>$page
  ], $overrides));
};
?>
<div class="split">
  <!-- Liste -->
  <div class="card">
    <h1>Bestände</h1>

    <form method="get" class="filters">
      <input type="hidden" name="p" value="bestaende">
      <div>
        <label for="q">Suche</label>
        <input type="text" id="q" name="q"
               placeholder="<?= $grpby==='mat' ? 'Material...' : 'Material, Variante, SKU, Farbe, Größe...' ?>"
               value="<?= e($q) ?>">
      </div>

      <div>
        <label for="grp">Gruppe</label>
        <select id="grp" name="grp">
          <option value="">— alle —</option>
          <?php foreach ($gruppen as $g): ?>
            <option value="<?= (int)$g['MaterialgruppeID'] ?>" <?= ($grp!=='' && (int)$grp===(int)$g['MaterialgruppeID']?'selected':'') ?>>
              <?= e($g['Gruppenname']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label for="man">Hersteller</label>
        <select id="man" name="man">
          <option value="">— alle —</option>
          <?php foreach ($hersteller as $h): ?>
            <option value="<?= (int)$h['HerstellerID'] ?>" <?= ($man!=='' && (int)$man===(int)$h['HerstellerID']?'selected':'') ?>>
              <?= e($h['Name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label for="loc">Lagerort</label>
        <select id="loc" name="loc">
          <option value="">— gesamt —</option>
          <?php foreach ($lagerorte as $l): ?>
            <option value="<?= (int)$l['LagerortID'] ?>" <?= ($loc!=='' && (int)$loc===(int)$l['LagerortID']?'selected':'') ?>>
              <?= e($l['Code'].' – '.$l['Bezeichnung']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label for="grpby">Gruppierung</label>
        <select id="grpby" name="grpby">
          <option value="var" <?= $grpby==='var'?'selected':'' ?>>Variante</option>
          <option value="mat" <?= $grpby==='mat'?'selected':'' ?>>Material</option>
        </select>
      </div>

      <div>
        <label for="sort">Sortierung</label>
        <select id="sort" name="sort">
          <option value="name" <?= $sort==='name'?'selected':'' ?>>Name A–Z</option>
          <option value="bestand_desc" <?= $sort==='bestand_desc'?'selected':'' ?>>Bestand (↓)</option>
        </select>
      </div>

      <div>
        <label for="zero">Nullbestände</label>
        <select id="zero" name="zero">
          <option value="0" <?= $zero==='0'?'selected':'' ?>>ausblenden</option>
          <option value="1" <?= $zero==='1'?'selected':'' ?>>anzeigen</option>
        </select>
      </div>

      <div style="display:flex;gap:8px;align-items:flex-end">
        <button class="btn btn-primary" type="submit">Filtern</button>
        <a class="btn btn-secondary" href="<?= e($base) ?>/?p=bestaende">Zurücksetzen</a>
      </div>
    </form>

    <div class="hint" style="margin-bottom:8px">
      <?= $total ?> Treffer • Seite <?= $page ?> von <?= $pages ?>
      <?php if ($loc!==''): ?> • Lagerort: gefiltert<?php endif; ?>
    </div>

    <?php if ($grpby==='var'): ?>
      <table>
        <thead>
          <tr>
            <th>Material</th>
            <th>Variante</th>
            <th>Farbe</th>
            <th>Größe</th>
            <th class="muted">SKU</th>
            <th>Gruppe</th>
            <th>Hersteller</th>
            <th>Lagerort</th>
            <th style="text-align:right">Bestand</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($rows)): ?>
            <tr><td colspan="9" class="muted">Keine Bestände gefunden.</td></tr>
          <?php else: ?>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td><?= e($r['MaterialName']) ?></td>
                <td><?= e($r['VariantenBezeichnung']) ?></td>
                <td><?= e($r['Farbe'] ?? '') ?></td>
                <td><?= e($r['Groesse'] ?? '') ?></td>
                <td class="muted"><?= e($r['SKU'] ?? '') ?></td>
                <td><?= e($r['Materialgruppe'] ?? '') ?></td>
                <td><?= e($r['HerstellerName'] ?? '') ?></td>
                <td>
                  <?php
                    if ($loc==='') { echo 'gesamt'; }
                    else {
                      $lid = (int)($r['LagerortID'] ?? 0);
                      if ($lid===0) echo '—';
                      else {
                        foreach ($lagerorte as $l) {
                          if ((int)$l['LagerortID'] === $lid) { echo e($l['Code']); break; }
                        }
                      }
                    }
                  ?>
                </td>
                <td style="text-align:right;<?= ((float)$r['Bestand']<0?'color:#b91c1c;font-weight:600':'') ?>">
                  <?= rtrim(rtrim(number_format((float)$r['Bestand'],3,'.',''), '0'),'.') ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>Material</th>
            <th>Gruppe</th>
            <th>Hersteller</th>
            <th style="text-align:right">Bestand</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($rows)): ?>
            <tr><td colspan="4" class="muted">Keine Bestände gefunden.</td></tr>
          <?php else: ?>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td><?= e($r['MaterialName']) ?></td>
                <td><?= e($r['Materialgruppe'] ?? '') ?></td>
                <td><?= e($r['HerstellerName'] ?? '') ?></td>
                <td style="text-align:right;<?= ((float)$r['Bestand']<0?'color:#b91c1c;font-weight:600':'') ?>">
                  <?= rtrim(rtrim(number_format((float)$r['Bestand'],3,'.',''), '0'),'.') ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <?php if ($pages > 1): ?>
      <div class="pagination">
        <a class="btn btn-secondary" href="?<?= $qs(['page'=>1]) ?>" <?= $page==1?'style="opacity:.5;pointer-events:none"':'' ?>>« Erste</a>
        <a class="btn btn-secondary" href="?<?= $qs(['page'=>max(1,$page-1)]) ?>" <?= $page==1?'style="opacity:.5;pointer-events:none"':'' ?>>‹ Zurück</a>
        <span class="muted">Seite <?= $page ?>/<?= $pages ?></span>
        <a class="btn btn-secondary" href="?<?= $qs(['page'=>min($pages,$page+1)]) ?>" <?= $page==$pages?'style="opacity:.5;pointer-events:none"':'' ?>>Weiter ›</a>
        <a class="btn btn-secondary" href="?<?= $qs(['page'=>$pages]) ?>" <?= $page==$pages?'style="opacity:.5;pointer-events:none"':'' ?>>Letzte »</a>
      </div>
    <?php endif; ?>
  </div>

  <!-- Hinweis-Karte -->
  <div class="card">
    <h2>Hinweise</h2>
    <ul class="muted" style="margin-left:1rem">
      <li>Bestände werden aus <code>Materialbeleg</code> über die Bewegungsrichtung summiert.</li>
      <li>„Lagerort = gesamt“ zeigt die Summe über alle Lagerorte; mit Lagerort-Filter werden nur Bestände dort gezeigt.</li>
      <li>Negative Bestände werden rot hervorgehoben.</li>
      <li>Gruppierung wahlweise nach Variante oder Material.</li>
    </ul>
  </div>
</div>
