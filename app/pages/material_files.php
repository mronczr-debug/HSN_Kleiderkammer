<?php
declare(strict_types=1);

/**
 * Upload- & Dateiverwaltung pro Material
 * - akzeptiert: png, jpg, jpeg, webp, pdf
 * - max. 10 MB
 * - speichert unter /public/uploads/materials/{MaterialID}/<random>.<ext>
 * - schreibt Datensatz in dbo.MaterialDatei
 */

$materialId = isset($_GET['material_id']) ? (int)$_GET['material_id'] : 0;
if ($materialId <= 0) { http_response_code(400); exit('material_id fehlt.'); }

$sqlMat = "SELECT MaterialID, MaterialName FROM dbo.Material WHERE MaterialID = :id";
$st = $pdo->prepare($sqlMat);
$st->execute([':id'=>$materialId]);
$material = $st->fetch(PDO::FETCH_ASSOC);
if (!$material) { http_response_code(404); exit('Material nicht gefunden.'); }

$errors = [];
$success = null;

$allowedExt  = ['png','jpg','jpeg','webp','pdf'];
$allowedMime = ['image/png','image/jpeg','image/webp','application/pdf'];
$maxBytes    = 10 * 1024 * 1024;

if (($_POST['action'] ?? '') === 'upload') {
  if (!csrf_check($_POST['csrf'] ?? '')) {
    $errors['_'] = 'Sicherheits-Token ungültig. Bitte Seite neu laden.';
  } elseif (!isset($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
    $errors['_'] = 'Bitte eine Datei auswählen.';
  } else {
    $f = $_FILES['file'];
    if ($f['error'] !== UPLOAD_ERR_OK) {
      $errors['_'] = 'Upload-Fehler (Code '.$f['error'].').';
    } elseif ($f['size'] <= 0 || $f['size'] > $maxBytes) {
      $errors['_'] = 'Datei ist leer oder größer als 10 MB.';
    } else {
      $orig = (string)$f['name'];
      $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
      if (!in_array($ext, $allowedExt, true)) {
        $errors['_'] = 'Unerlaubter Dateityp. Erlaubt: '.implode(', ', $allowedExt);
      } else {
        $fi = new finfo(FILEINFO_MIME_TYPE);
        $mime = $fi->file($f['tmp_name']) ?: 'application/octet-stream';
        if (!in_array($mime, $allowedMime, true)) {
          $errors['_'] = 'Unerlaubter Inhaltstyp ('.$mime.').';
        }
      }
    }

    if (!$errors) {
      $baseDir   = uploads_dir();
      $relFolder = 'materials/'.$materialId;
      $targetDir = safe_join($baseDir, $relFolder);
      if (!is_dir($targetDir) && !@mkdir($targetDir, 0775, true)) {
        $errors['_'] = 'Verzeichnis konnte nicht angelegt werden.';
      } else {
        $newName = random_name($ext);
        $absPath = safe_join($targetDir, $newName);
        $relPath = $relFolder.'/'.$newName; // Speichern relativ zu /uploads

        if (!@move_uploaded_file($f['tmp_name'], $absPath)) {
          $errors['_'] = 'Datei konnte nicht gespeichert werden.';
        } else {
          // DB schreiben
          $ins = $pdo->prepare("
            INSERT INTO dbo.MaterialDatei (MaterialID, RelPath, Originalname, ContentType, ByteSize, IsPrimary)
            VALUES (:mid, :rel, :orig, :ct, :sz, :prim)
          ");
          $ins->execute([
            ':mid'  => $materialId,
            ':rel'  => $relPath,
            ':orig' => $orig,
            ':ct'   => $mime,
            ':sz'   => (int)$f['size'],
            ':prim' => 0,
          ]);
          $success = 'Datei wurde hochgeladen.';
          // Weiterleitung, um F5-Doppelklick zu vermeiden
          flash('success', $success);
          header('Location: '.base_url().'/?p=material_files&material_id='.$materialId);
          exit;
        }
      }
    }
  }
}

if (($_POST['action'] ?? '') === 'set_primary') {
  if (!csrf_check($_POST['csrf'] ?? '')) {
    $errors['_'] = 'Sicherheits-Token ungültig.';
  } else {
    $did = (int)($_POST['datei_id'] ?? 0);
    $pdo->beginTransaction();
    try {
      $pdo->prepare("UPDATE dbo.MaterialDatei SET IsPrimary = 0 WHERE MaterialID = :m")->execute([':m'=>$materialId]);
      $pdo->prepare("UPDATE dbo.MaterialDatei SET IsPrimary = 1 WHERE DateiID = :d AND MaterialID = :m")->execute([':d'=>$did, ':m'=>$materialId]);
      $pdo->commit();
      flash('success', 'Primärbild gesetzt.');
      header('Location: '.base_url().'/?p=material_files&material_id='.$materialId);
      exit;
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $errors['_'] = 'Konnte Primärbild nicht setzen: '.$e->getMessage();
    }
  }
}

if (($_POST['action'] ?? '') === 'delete') {
  if (!csrf_check($_POST['csrf'] ?? '')) {
    $errors['_'] = 'Sicherheits-Token ungültig.';
  } else {
    $did = (int)($_POST['datei_id'] ?? 0);
    $row = $pdo->prepare("SELECT RelPath FROM dbo.MaterialDatei WHERE DateiID = :d AND MaterialID = :m");
    $row->execute([':d'=>$did, ':m'=>$materialId]);
    $r = $row->fetch(PDO::FETCH_ASSOC);
    if ($r) {
      $abs = safe_join(uploads_dir(), $r['RelPath']);
      $pdo->beginTransaction();
      try {
        $pdo->prepare("DELETE FROM dbo.MaterialDatei WHERE DateiID = :d AND MaterialID = :m")->execute([':d'=>$did, ':m'=>$materialId]);
        if (is_file($abs)) { @unlink($abs); }
        $pdo->commit();
        flash('success', 'Datei gelöscht.');
        header('Location: '.base_url().'/?p=material_files&material_id='.$materialId);
        exit;
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errors['_'] = 'Löschen fehlgeschlagen: '.$e->getMessage();
      }
    }
  }
}

/* Dateien laden */
$files = $pdo->prepare("
  SELECT DateiID, RelPath, Originalname, ContentType, ByteSize, IsPrimary, CreatedAt
  FROM dbo.MaterialDatei
  WHERE MaterialID = :m
  ORDER BY IsPrimary DESC, CreatedAt DESC
");
$files->execute([':m'=>$materialId]);
$rows = $files->fetchAll(PDO::FETCH_ASSOC);

$base = base_url();
$uploadsBase = uploads_url();
?>
<div class="card">
  <h1>Datei-Uploads</h1>
  <div class="subtitle">
    <strong>Material:</strong> <?= e($material['MaterialName']) ?>
  </div>

  <?php if ($msg = flash('success')): ?>
    <div class="alert alert-success"><?= e($msg) ?></div>
  <?php endif; ?>
  <?php if (!empty($errors['_'])): ?>
    <div class="alert alert-error"><?= e($errors['_']) ?></div>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data" class="grid1" id="upload-form">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="upload">
    <div class="dropzone" id="dropzone">
      <div><strong>Datei hierher ziehen</strong> oder klicken, um auszuwählen</div>
      <div class="dz-hint">Erlaubt: PNG, JPG, WEBP, PDF • max. 10 MB</div>
      <input type="file" name="file" id="file" accept=".png,.jpg,.jpeg,.webp,.pdf" style="position:absolute;opacity:0;inset:0;cursor:pointer">
    </div>
    <div style="display:flex;gap:8px;align-items:center">
      <button class="btn btn-primary" type="submit">Hochladen</button>
      <a class="btn btn-secondary" href="<?= e($base) ?>/?p=materials">Zur Materialliste</a>
    </div>
  </form>

  <h2 style="margin-top:1.2rem">Vorhandene Dateien</h2>
  <?php if (empty($rows)): ?>
    <p class="hint">Noch keine Dateien vorhanden.</p>
  <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>Vorschau</th>
          <th>Datei</th>
          <th>Typ</th>
          <th>Größe</th>
          <th>Hochgeladen</th>
          <th>Primär</th>
          <th>Aktion</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r):
          $isImage = str_starts_with($r['ContentType'], 'image/');
          $url = $uploadsBase.'/'.str_replace('\\','/',$r['RelPath']);
        ?>
          <tr>
            <td>
              <?php if ($isImage): ?>
                <img src="<?= e($url) ?>" alt="" style="width:56px;height:56px;object-fit:cover;border-radius:8px;border:1px solid #e5e7eb">
              <?php else: ?>
                <span class="muted">—</span>
              <?php endif; ?>
            </td>
            <td>
              <div><a href="<?= e($url) ?>" target="_blank"><?= e($r['Originalname']) ?></a></div>
              <div class="muted"><?= e($r['RelPath']) ?></div>
            </td>
            <td><?= e($r['ContentType']) ?></td>
            <td><?= number_format((int)$r['ByteSize']/1024, 0, ',', '.') ?> KB</td>
            <td><?= e((string)$r['CreatedAt']) ?></td>
            <td><?= $r['IsPrimary'] ? '<span class="pill on">Primär</span>' : '<span class="pill">—</span>' ?></td>
            <td style="display:flex;gap:8px;flex-wrap:wrap">
              <?php if (!$r['IsPrimary']): ?>
                <form method="post" onsubmit="return confirm('Als Primärbild setzen?')">
                  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="action" value="set_primary">
                  <input type="hidden" name="datei_id" value="<?= (int)$r['DateiID'] ?>">
                  <button class="btn btn-secondary" type="submit">Als Primär</button>
                </form>
              <?php endif; ?>
              <form method="post" onsubmit="return confirm('Datei wirklich löschen?')">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="datei_id" value="<?= (int)$r['DateiID'] ?>">
                <button class="btn btn-secondary" type="submit">Löschen</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<script>
(function(){
  const dz = document.getElementById('dropzone');
  const fi = document.getElementById('file');

  ['dragenter','dragover'].forEach(ev => dz.addEventListener(ev, e => {
    e.preventDefault(); e.stopPropagation(); dz.classList.add('dragover');
  }));
  ['dragleave','drop'].forEach(ev => dz.addEventListener(ev, e => {
    e.preventDefault(); e.stopPropagation(); dz.classList.remove('dragover');
  }));
  dz.addEventListener('drop', e => {
    if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) {
      fi.files = e.dataTransfer.files;
    }
  });
  dz.addEventListener('click', () => fi.click());
})();
</script>
