<?php
declare(strict_types=1);

/**
 * Mitarbeiter – Liste links, Anlegen/Bearbeiten rechts
 * - Verwendet INSERT ... OUTPUT INSERTED.MitarbeiterID für stabile ID-Rückgabe (SQLSRV)
 * - Keine mehrfachen benannten Parameter in Statements
 * - CSRF-Schutz
 *
 * Erwartet: $pdo (PDO SQLSRV), Helpers e(), csrf_token(), csrf_check(), base_url()
 */

$base = rtrim(base_url(), '/');

/* ========= Lookups ========= */
$typList = $pdo->query("SELECT TypID, Bezeichnung FROM dbo.MitarbeiterTyp ORDER BY Bezeichnung")->fetchAll(PDO::FETCH_ASSOC);
$katList = $pdo->query("SELECT KategorieID, Bezeichnung FROM dbo.MitarbeiterKategorie ORDER BY Bezeichnung")->fetchAll(PDO::FETCH_ASSOC);

/* ========= Filter & Paging (linke Liste) ========= */
$q      = trim((string)($_GET['q'] ?? ''));
$typ    = $_GET['typ'] ?? '';
$kat    = $_GET['kat'] ?? '';
$aktiv  = $_GET['aktiv'] ?? ''; // '', '1', '0'
$page   = max(1, (int)($_GET['page'] ?? 1));
$ps     = 15;
$off    = ($page-1) * $ps;

$where = [];
$params = [];

if ($q !== '') {
  $where[] = "(ms.Personalnummer LIKE :qs OR ms.Vorname LIKE :qs OR ms.Nachname LIKE :qs OR ISNULL(ms.Abteilung,'') LIKE :qs)";
  $params[':qs'] = '%'.$q.'%';
}
if ($typ !== '' && ctype_digit((string)$typ)) {
  $where[] = "ms.MitarbeiterTypID = :typ";
  $params[':typ'] = (int)$typ;
}
if ($kat !== '' && ctype_digit((string)$kat)) {
  $where[] = "ms.MitarbeiterKategorieID = :kat";
  $params[':kat'] = (int)$kat;
}
if ($aktiv === '0' || $aktiv === '1') {
  $where[] = "ms.Aktiv = :ak";
  $params[':ak'] = (int)$aktiv;
}
$whereSql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

/* Count */
$sqlCnt = "
  SELECT COUNT(*) AS cnt
  FROM dbo.MitarbeiterStamm ms
  $whereSql
";
$stCnt = $pdo->prepare($sqlCnt);
foreach ($params as $k=>$v) $stCnt->bindValue($k, $v, is_int($v)?PDO::PARAM_INT:PDO::PARAM_STR);
$stCnt->execute();
$total = (int)$stCnt->fetch(PDO::FETCH_ASSOC)['cnt'];
$pages = max(1, (int)ceil($total / $ps));

/* Rows */
$sqlRows = "
  SELECT
    ms.MitarbeiterID,
    ms.Personalnummer,
    ms.Vorname, ms.Nachname,
    ms.Aktiv,
    mt.Bezeichnung AS MitarbeiterTyp,
    mk.Bezeichnung AS MitarbeiterKategorie,
    ms.Abteilung, ms.Position
  FROM dbo.MitarbeiterStamm ms
  JOIN dbo.MitarbeiterTyp mt       ON mt.TypID = ms.MitarbeiterTypID
  JOIN dbo.MitarbeiterKategorie mk ON mk.KategorieID = ms.MitarbeiterKategorieID
  $whereSql
  ORDER BY ms.Nachname ASC, ms.Vorname ASC
  OFFSET :off ROWS FETCH NEXT :ps ROWS ONLY
";
$st = $pdo->prepare($sqlRows);
foreach ($params as $k=>$v) $st->bindValue($k, $v, is_int($v)?PDO::PARAM_INT:PDO::PARAM_STR);
$st->bindValue(':off', $off, PDO::PARAM_INT);
$st->bindValue(':ps',  $ps,  PDO::PARAM_INT);
$st->execute();
$list = $st->fetchAll(PDO::FETCH_ASSOC);

/* ========= Edit-Kontext ========= */
$editId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$editing = $editId > 0;

/* ========= Formularwerte initial ========= */
$form = [
  'csrf'             => $_POST['csrf'] ?? '',
  'Personalnummer'   => trim((string)($_POST['Personalnummer'] ?? '')),
  'Vorname'          => trim((string)($_POST['Vorname'] ?? '')),
  'Nachname'         => trim((string)($_POST['Nachname'] ?? '')),
  'Geschlecht'       => strtoupper(trim((string)($_POST['Geschlecht'] ?? ''))),
  'Geburtsdatum'     => trim((string)($_POST['Geburtsdatum'] ?? '')),
  'Eintrittsdatum'   => trim((string)($_POST['Eintrittsdatum'] ?? '')),
  'Austrittsdatum'   => trim((string)($_POST['Austrittsdatum'] ?? '')),
  'Abteilung'        => trim((string)($_POST['Abteilung'] ?? '')),
  'Position'         => trim((string)($_POST['Position'] ?? '')),
  'MitarbeiterTypID' => $_POST['MitarbeiterTypID'] ?? '',
  'MitarbeiterKategorieID' => $_POST['MitarbeiterKategorieID'] ?? '',
  'Standard_Schuhgröße'    => $_POST['Standard_Schuhgröße'] ?? '',
  'Standard_Kleidergröße'  => trim((string)($_POST['Standard_Kleidergröße'] ?? '')),
  'Aktiv'            => isset($_POST['Aktiv']) ? '1' : ($editing ? '' : '1'), // Default 1 bei Neuanlage
];

/* ========= Wenn GET Edit: Daten laden (falls kein POST) ========= */
$errors = [];
if ($editing && $_SERVER['REQUEST_METHOD'] !== 'POST') {
  $load = $pdo->prepare("
    SELECT *
    FROM dbo.MitarbeiterStamm
    WHERE MitarbeiterID = :id
  ");
  $load->execute([':id'=>$editId]);
  $row = $load->fetch(PDO::FETCH_ASSOC);
  if ($row) {
    $form = array_merge($form, [
      'Personalnummer'   => (string)$row['Personalnummer'],
      'Vorname'          => (string)$row['Vorname'],
      'Nachname'         => (string)$row['Nachname'],
      'Geschlecht'       => (string)($row['Geschlecht'] ?? ''),
      'Geburtsdatum'     => (string)($row['Geburtsdatum'] ?? ''),
      'Eintrittsdatum'   => (string)($row['Eintrittsdatum'] ?? ''),
      'Austrittsdatum'   => (string)($row['Austrittsdatum'] ?? ''),
      'Abteilung'        => (string)($row['Abteilung'] ?? ''),
      'Position'         => (string)($row['Position'] ?? ''),
      'MitarbeiterTypID' => (string)$row['MitarbeiterTypID'],
      'MitarbeiterKategorieID' => (string)$row['MitarbeiterKategorieID'],
      'Standard_Schuhgröße'    => ($row['Standard_Schuhgröße'] === null ? '' : (string)$row['Standard_Schuhgröße']),
      'Standard_Kleidergröße'  => (string)($row['Standard_Kleidergröße'] ?? ''),
      'Aktiv'            => (string)((int)$row['Aktiv']),
    ]);
  } else {
    $editing = false;
    $editId = 0;
  }
}

/* ========= POST: Create/Update ========= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? ($editing ? 'update' : 'create');

  // CSRF
  if (!csrf_check($form['csrf'])) {
    $errors['csrf'] = 'Sicherheits-Token ungültig. Bitte Seite neu laden.';
  }

  // Validierung
  if ($form['Personalnummer'] === '' || strlen($form['Personalnummer']) > 20) {
    $errors['Personalnummer'] = 'Personalnummer erforderlich (max. 20 Zeichen).';
  }
  if ($form['Vorname'] === '' || mb_strlen($form['Vorname']) > 50) {
    $errors['Vorname'] = 'Vorname erforderlich (max. 50 Zeichen).';
  }
  if ($form['Nachname'] === '' || mb_strlen($form['Nachname']) > 50) {
    $errors['Nachname'] = 'Nachname erforderlich (max. 50 Zeichen).';
  }
  if ($form['MitarbeiterTypID'] === '' || !ctype_digit((string)$form['MitarbeiterTypID'])) {
    $errors['MitarbeiterTypID'] = 'Bitte einen Mitarbeiter-Typ wählen.';
  }
  if ($form['MitarbeiterKategorieID'] === '' || !ctype_digit((string)$form['MitarbeiterKategorieID'])) {
    $errors['MitarbeiterKategorieID'] = 'Bitte eine Mitarbeiter-Kategorie wählen.';
  }
  if ($form['Geschlecht'] !== '' && !in_array($form['Geschlecht'], ['M','W','D'], true)) {
    $errors['Geschlecht'] = 'Ungültiger Wert (erlaubt: M, W, D).';
  }
  // Dates
  $dateOrNull = function (string $s): ?string {
    if ($s === '') return null;
    $d = date_create($s);
    return $d ? date_format($d, 'Y-m-d') : null;
  };
  $gd = $dateOrNull($form['Geburtsdatum']);
  if ($form['Geburtsdatum'] !== '' && $gd === null) {
    $errors['Geburtsdatum'] = 'Ungültiges Datum (YYYY-MM-DD).';
  }
  $ed = $dateOrNull($form['Eintrittsdatum']);
  if ($ed === null) { $errors['Eintrittsdatum'] = 'Eintrittsdatum erforderlich (YYYY-MM-DD).'; }
  $ad = $dateOrNull($form['Austrittsdatum']);
  if ($form['Austrittsdatum'] !== '' && $ad === null) {
    $errors['Austrittsdatum'] = 'Ungültiges Datum (YYYY-MM-DD).';
  }
  // Schuhgröße (tinyint)
  if ($form['Standard_Schuhgröße'] !== '') {
    if (!ctype_digit((string)$form['Standard_Schuhgröße'])) {
      $errors['Standard_Schuhgröße'] = 'Zahl erwartet.';
    } else {
      $iv = (int)$form['Standard_Schuhgröße'];
      if ($iv < 0 || $iv > 255) $errors['Standard_Schuhgröße'] = 'Wert 0–255.';
    }
  }
  if ($form['Standard_Kleidergröße'] !== '' && mb_strlen($form['Standard_Kleidergröße']) > 10) {
    $errors['Standard_Kleidergröße'] = 'Max. 10 Zeichen.';
  }

  if (!$errors) {
    try {
      $pdo->beginTransaction();

      if ($action === 'create') {
        // STABIL: OUTPUT INSERTED.MitarbeiterID → echte Resultset-Zeile mit Feld
        $sql = "
          INSERT INTO dbo.MitarbeiterStamm
            (Personalnummer, Vorname, Nachname, Geschlecht, Geburtsdatum,
             Eintrittsdatum, Austrittsdatum, Abteilung, Position,
             MitarbeiterTypID, MitarbeiterKategorieID,
             Standard_Schuhgröße, Standard_Kleidergröße, Aktiv)
          OUTPUT INSERTED.MitarbeiterID
          VALUES
            (:pnr, :vn, :nn, :gs, :gd, :ed, :ad, :abt, :pos,
             :typ, :kat, :shoe, :cloth, :ak)
        ";
        $stIns = $pdo->prepare($sql);
        $stIns->execute([
          ':pnr'  => $form['Personalnummer'],
          ':vn'   => $form['Vorname'],
          ':nn'   => $form['Nachname'],
          ':gs'   => ($form['Geschlecht'] === '' ? null : $form['Geschlecht']),
          ':gd'   => $gd,
          ':ed'   => $ed,
          ':ad'   => $ad,
          ':abt'  => ($form['Abteilung'] === '' ? null : $form['Abteilung']),
          ':pos'  => ($form['Position'] === '' ? null : $form['Position']),
          ':typ'  => (int)$form['MitarbeiterTypID'],
          ':kat'  => (int)$form['MitarbeiterKategorieID'],
          ':shoe' => ($form['Standard_Schuhgröße'] === '' ? null : (int)$form['Standard_Schuhgröße']),
          ':cloth'=> ($form['Standard_Kleidergröße'] === '' ? null : $form['Standard_Kleidergröße']),
          ':ak'   => ($form['Aktiv'] === '1' ? 1 : 0),
        ]);
        $newId = (int)$stIns->fetch(PDO::FETCH_COLUMN);
        $pdo->commit();
        $_SESSION['flash_success'] = 'Mitarbeiter wurde angelegt.';
        header('Location: '.$base.'/?p=mitarbeiter&id='.$newId);
        exit;

      } else {
        // UPDATE – kein fetch() aufrufen!
        if (!$editing) { throw new RuntimeException('Ungültiger Bearbeiten-Kontext.'); }
        $sql = "
          UPDATE dbo.MitarbeiterStamm
          SET Personalnummer = :pnr,
              Vorname = :vn,
              Nachname = :nn,
              Geschlecht = :gs,
              Geburtsdatum = :gd,
              Eintrittsdatum = :ed,
              Austrittsdatum = :ad,
              Abteilung = :abt,
              Position = :pos,
              MitarbeiterTypID = :typ,
              MitarbeiterKategorieID = :kat,
              Standard_Schuhgröße = :shoe,
              Standard_Kleidergröße = :cloth,
              Aktiv = :ak
          WHERE MitarbeiterID = :id
        ";
        $stUpd = $pdo->prepare($sql);
        $stUpd->execute([
          ':pnr'  => $form['Personalnummer'],
          ':vn'   => $form['Vorname'],
          ':nn'   => $form['Nachname'],
          ':gs'   => ($form['Geschlecht'] === '' ? null : $form['Geschlecht']),
          ':gd'   => $gd,
          ':ed'   => $ed,
          ':ad'   => $ad,
          ':abt'  => ($form['Abteilung'] === '' ? null : $form['Abteilung']),
          ':pos'  => ($form['Position'] === '' ? null : $form['Position']),
          ':typ'  => (int)$form['MitarbeiterTypID'],
          ':kat'  => (int)$form['MitarbeiterKategorieID'],
          ':shoe' => ($form['Standard_Schuhgröße'] === '' ? null : (int)$form['Standard_Schuhgröße']),
          ':cloth'=> ($form['Standard_Kleidergröße'] === '' ? null : $form['Standard_Kleidergröße']),
          ':ak'   => ($form['Aktiv'] === '1' ? 1 : 0),
          ':id'   => $editId,
        ]);
        $pdo->commit();
        $_SESSION['flash_success'] = 'Mitarbeiter wurde gespeichert.';
        header('Location: '.$base.'/?p=mitarbeiter&id='.$editId);
        exit;
      }

    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $errors['_'] = 'Speichern fehlgeschlagen: '.$e->getMessage();
    }
  }
}

/* ========= Flash aus Session ========= */
$flashSuccess = $_SESSION['flash_success'] ?? null; unset($_SESSION['flash_success']);

/* ========= Helpers ========= */
$qs = function(array $overrides=[]) use($q,$typ,$kat,$aktiv,$page){
  return http_build_query(array_merge([
    'p'=>'mitarbeiter','q'=>$q,'typ'=>$typ,'kat'=>$kat,'aktiv'=>$aktiv,'page'=>$page
  ], $overrides));
};
?>
<div class="split">
  <!-- Liste -->
  <div class="card">
    <h1>Mitarbeiter</h1>

    <?php if ($flashSuccess): ?>
      <div class="alert alert-success"><?= e($flashSuccess) ?></div>
    <?php endif; ?>

    <form method="get" class="filters">
      <input type="hidden" name="p" value="mitarbeiter">
      <div>
        <label for="q">Suche</label>
        <input type="text" id="q" name="q" placeholder="Name, Personalnummer, Abteilung..." value="<?= e($q) ?>">
      </div>
      <div>
        <label for="typ">Typ</label>
        <select id="typ" name="typ">
          <option value="">— alle —</option>
          <?php foreach ($typList as $t): ?>
            <option value="<?= (int)$t['TypID'] ?>" <?= ($typ!=='' && (int)$typ===(int)$t['TypID']?'selected':'') ?>><?= e($t['Bezeichnung']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="kat">Kategorie</label>
        <select id="kat" name="kat">
          <option value="">— alle —</option>
          <?php foreach ($katList as $k): ?>
            <option value="<?= (int)$k['KategorieID'] ?>" <?= ($kat!=='' && (int)$kat===(int)$k['KategorieID']?'selected':'') ?>><?= e($k['Bezeichnung']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="aktiv">Status</label>
        <select id="aktiv" name="aktiv">
          <option value="">— alle —</option>
          <option value="1" <?= ($aktiv==='1'?'selected':'') ?>>aktiv</option>
          <option value="0" <?= ($aktiv==='0'?'selected':'') ?>>inaktiv</option>
        </select>
      </div>
      <div style="display:flex;gap:8px;align-items:flex-end">
        <button class="btn btn-primary" type="submit">Filtern</button>
        <a class="btn btn-secondary" href="<?= e($base) ?>/?p=mitarbeiter">Zurücksetzen</a>
      </div>
    </form>

    <div class="hint" style="margin-bottom:8px">
      <?= $total ?> Treffer • Seite <?= $page ?> von <?= $pages ?>
    </div>

    <table>
      <thead>
        <tr>
          <th>Pers.-Nr</th>
          <th>Name</th>
          <th>Typ</th>
          <th>Kategorie</th>
          <th>Abteilung</th>
          <th>Position</th>
          <th>Status</th>
          <th>Aktion</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($list)): ?>
          <tr><td colspan="8" class="muted">Keine Mitarbeiter gefunden.</td></tr>
        <?php else: ?>
          <?php foreach ($list as $r): ?>
            <tr<?= $editing && $editId===(int)$r['MitarbeiterID'] ? ' style="outline:2px solid #e5e7eb;border-radius:6px"' : '' ?>>
              <td><?= e($r['Personalnummer']) ?></td>
              <td><strong><?= e($r['Nachname'].', '.$r['Vorname']) ?></strong></td>
              <td><?= e($r['MitarbeiterTyp']) ?></td>
              <td><?= e($r['MitarbeiterKategorie']) ?></td>
              <td><?= e($r['Abteilung'] ?? '') ?></td>
              <td><?= e($r['Position'] ?? '') ?></td>
              <td><?= ((int)$r['Aktiv']===1 ? '<span class="pill on">aktiv</span>' : '<span class="pill off">inaktiv</span>') ?></td>
              <td>
                <a class="btn btn-secondary" href="<?= e($base) ?>/?p=mitarbeiter&id=<?= (int)$r['MitarbeiterID'] ?>">Bearbeiten</a>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>

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

  <!-- Anlage/Bearbeiten -->
  <div class="card">
    <h2><?= $editing ? 'Mitarbeiter bearbeiten' : 'Neuen Mitarbeiter anlegen' ?></h2>

    <?php if (!empty($errors['_'])): ?>
      <div class="alert alert-error"><?= e($errors['_']) ?></div>
    <?php endif; ?>

    <form method="post" class="grid1">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
      <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int)$editId ?>"><?php endif; ?>

      <div class="grid2">
        <div>
          <label for="Personalnummer">Personalnummer *</label>
          <input type="text" id="Personalnummer" name="Personalnummer" maxlength="20" required value="<?= e($form['Personalnummer']) ?>">
          <?php if (!empty($errors['Personalnummer'])): ?><div class="alert alert-error"><?= e($errors['Personalnummer']) ?></div><?php endif; ?>
        </div>
        <div>
          <label for="Geschlecht">Geschlecht</label>
          <select id="Geschlecht" name="Geschlecht">
            <option value="">—</option>
            <?php foreach (['M'=>'Männlich','W'=>'Weiblich','D'=>'Divers'] as $k=>$lbl): ?>
              <option value="<?= $k ?>" <?= ($form['Geschlecht']===$k?'selected':'') ?>><?= e($lbl) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if (!empty($errors['Geschlecht'])): ?><div class="alert alert-error"><?= e($errors['Geschlecht']) ?></div><?php endif; ?>
        </div>
      </div>

      <div class="grid2">
        <div>
          <label for="Vorname">Vorname *</label>
          <input type="text" id="Vorname" name="Vorname" maxlength="50" required value="<?= e($form['Vorname']) ?>">
          <?php if (!empty($errors['Vorname'])): ?><div class="alert alert-error"><?= e($errors['Vorname']) ?></div><?php endif; ?>
        </div>
        <div>
          <label for="Nachname">Nachname *</label>
          <input type="text" id="Nachname" name="Nachname" maxlength="50" required value="<?= e($form['Nachname']) ?>">
          <?php if (!empty($errors['Nachname'])): ?><div class="alert alert-error"><?= e($errors['Nachname']) ?></div><?php endif; ?>
        </div>
      </div>

      <div class="grid2">
        <div>
          <label for="Geburtsdatum">Geburtsdatum</label>
          <input type="date" id="Geburtsdatum" name="Geburtsdatum" value="<?= e($form['Geburtsdatum']) ?>">
          <?php if (!empty($errors['Geburtsdatum'])): ?><div class="alert alert-error"><?= e($errors['Geburtsdatum']) ?></div><?php endif; ?>
        </div>
        <div>
          <label for="Eintrittsdatum">Eintrittsdatum *</label>
          <input type="date" id="Eintrittsdatum" name="Eintrittsdatum" required value="<?= e($form['Eintrittsdatum']) ?>">
          <?php if (!empty($errors['Eintrittsdatum'])): ?><div class="alert alert-error"><?= e($errors['Eintrittsdatum']) ?></div><?php endif; ?>
        </div>
      </div>

      <div class="grid2">
        <div>
          <label for="Austrittsdatum">Austrittsdatum</label>
          <input type="date" id="Austrittsdatum" name="Austrittsdatum" value="<?= e($form['Austrittsdatum']) ?>">
          <?php if (!empty($errors['Austrittsdatum'])): ?><div class="alert alert-error"><?= e($errors['Austrittsdatum']) ?></div><?php endif; ?>
        </div>
        <div>
          <label for="MitarbeiterTypID">Mitarbeiter-Typ *</label>
          <select id="MitarbeiterTypID" name="MitarbeiterTypID" required>
            <option value="">— bitte wählen —</option>
            <?php foreach ($typList as $t): ?>
              <option value="<?= (int)$t['TypID'] ?>" <?= ($form['MitarbeiterTypID']!=='' && (int)$form['MitarbeiterTypID']===(int)$t['TypID']?'selected':'') ?>><?= e($t['Bezeichnung']) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if (!empty($errors['MitarbeiterTypID'])): ?><div class="alert alert-error"><?= e($errors['MitarbeiterTypID']) ?></div><?php endif; ?>
        </div>
      </div>

      <div class="grid2">
        <div>
          <label for="MitarbeiterKategorieID">Mitarbeiter-Kategorie *</label>
          <select id="MitarbeiterKategorieID" name="MitarbeiterKategorieID" required>
            <option value="">— bitte wählen —</option>
            <?php foreach ($katList as $k): ?>
              <option value="<?= (int)$k['KategorieID'] ?>" <?= ($form['MitarbeiterKategorieID']!=='' && (int)$form['MitarbeiterKategorieID']===(int)$k['KategorieID']?'selected':'') ?>><?= e($k['Bezeichnung']) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if (!empty($errors['MitarbeiterKategorieID'])): ?><div class="alert alert-error"><?= e($errors['MitarbeiterKategorieID']) ?></div><?php endif; ?>
        </div>
        <div>
          <label for="Abteilung">Abteilung</label>
          <input type="text" id="Abteilung" name="Abteilung" value="<?= e($form['Abteilung']) ?>">
        </div>
      </div>

      <div class="grid2">
        <div>
          <label for="Position">Position</label>
          <input type="text" id="Position" name="Position" value="<?= e($form['Position']) ?>">
        </div>
        <div>
          <label for="Standard_Schuhgröße">Standard Schuhgröße</label>
          <input type="text" id="Standard_Schuhgröße" name="Standard_Schuhgröße" inputmode="numeric" value="<?= e($form['Standard_Schuhgröße']) ?>">
          <?php if (!empty($errors['Standard_Schuhgröße'])): ?><div class="alert alert-error"><?= e($errors['Standard_Schuhgröße']) ?></div><?php endif; ?>
        </div>
      </div>

      <div class="grid2">
        <div>
          <label for="Standard_Kleidergröße">Standard Kleidergröße</label>
          <input type="text" id="Standard_Kleidergröße" name="Standard_Kleidergröße" maxlength="10" value="<?= e($form['Standard_Kleidergröße']) ?>">
          <?php if (!empty($errors['Standard_Kleidergröße'])): ?><div class="alert alert-error"><?= e($errors['Standard_Kleidergröße']) ?></div><?php endif; ?>
        </div>
        <div style="display:flex;align-items:flex-end">
          <label style="display:flex;gap:.5rem;align-items:center;margin:0">
            <input type="checkbox" name="Aktiv" value="1" <?= ($form['Aktiv']==='1' || ($editing && $form['Aktiv']==='')) ? 'checked' : '' ?>>
            Aktiv
          </label>
        </div>
      </div>

      <?php if (!empty($errors['csrf'])): ?>
        <div class="alert alert-error"><?= e($errors['csrf']) ?></div>
      <?php endif; ?>

      <div style="display:flex;gap:8px;align-items:center">
        <button class="btn btn-primary" type="submit"><?= $editing ? 'Speichern' : 'Anlegen' ?></button>
        <a class="btn btn-secondary" href="<?= e($base) ?>/?p=mitarbeiter">Neu / Zurücksetzen</a>
      </div>
    </form>
  </div>
</div>
