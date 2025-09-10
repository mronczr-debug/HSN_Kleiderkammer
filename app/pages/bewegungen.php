<?php
declare(strict_types=1);

/**
 * Warenbewegungen – Sammelseite
 * Zeigt die verfügbaren Vorgänge als Kacheln:
 * 1) Wareneingang erfassen
 * 2) Wareneingang korrigieren
 * 3) Sonstigen Warenausgang erfassen
 * 4) Sonstigen Warenausgang korrigieren
 * 5) Bestände korrigieren
 *
 * Erwartet: Helpers e(), base_url(); Layout/CSS aus app/layout.php
 */

$base = rtrim(base_url(), '/');
?>
<div class="card">
  <h1>Warenbewegungen</h1>
  <p class="muted" style="margin-top:.25rem">Bitte wähle einen Vorgang aus.</p>

  <!-- lokale, seitenbezogene Styles für die Kachel-Optik -->
  <style>
    .wb-grid{
      display:grid;
      grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
      gap:16px;
      margin-top:14px;
    }
    .wb-tile{
      border:1px solid #e5e7eb;
      border-radius:12px;
      padding:16px;
      background:#fff;
      display:flex;
      flex-direction:column;
      gap:8px;
      transition:box-shadow .15s ease, transform .05s ease;
    }
    .wb-tile:hover{ box-shadow:0 2px 18px rgba(0,0,0,.06); }
    .wb-title{
      font-weight:700;
      display:flex;
      align-items:center;
      gap:.5rem;
    }
    .wb-desc{ color:#6b7280; }
    .wb-actions{ margin-top:6px; display:flex; gap:8px; flex-wrap:wrap; }
    .wb-badge{
      display:inline-block;
      padding:.1rem .5rem;
      border-radius:999px;
      font-size:.78rem;
      border:1px solid #d1d5db;
      color:#374151;
      background:#f3f4f6;
    }
    .btn.disabled, a.btn.disabled{
      opacity:.5; pointer-events:none;
    }
  </style>

  <div class="wb-grid">
    <!-- 1) WE erfassen -->
    <div class="wb-tile">
      <div class="wb-title">📥 Wareneingang erfassen</div>
      <div class="wb-desc">Kopfdaten und Positionen aufnehmen und als Wareneingang (BA 101) buchen.</div>
      <div class="wb-actions">
        <a class="btn btn-primary" href="<?= e($base) ?>/?p=wareneingang">Öffnen</a>
        <span class="wb-badge">WE-Nummer automatisch</span>
      </div>
    </div>

    <!-- 2) WE korrigieren -->
    <div class="wb-tile">
      <div class="wb-title">🧾 Wareneingang korrigieren</div>
      <div class="wb-desc">Vorhandenen WE über WE-Nummer auswählen und vollständig oder teilweise stornieren (BA 102).</div>
      <div class="wb-actions">
        <a class="btn btn-primary" href="<?= e($base) ?>/?p=wareneingang_korrektur">Öffnen</a>
        <span class="wb-badge">Storno mit Referenz</span>
      </div>
    </div>

    <!-- 3) Sonstiger Warenausgang erfassen -->
    <div class="wb-tile">
      <div class="wb-title">📤 Sonstigen Warenausgang erfassen</div>
      <div class="wb-desc">Abgänge ohne Mitarbeiterbezug (z. B. Ausschuss, Umlagerung extern). Buchung mit BA 601.</div>
      <div class="wb-actions">
        <span class="btn btn-secondary disabled">Bald verfügbar</span>
        <span class="wb-badge">BA 601</span>
      </div>
    </div>

    <!-- 4) Sonstiger Warenausgang korrigieren -->
    <div class="wb-tile">
      <div class="wb-title">↩️ Sonstigen Warenausgang korrigieren</div>
      <div class="wb-desc">Bereits gebuchte Abgänge teilweise/komplett stornieren (BA 602) mit Referenz.</div>
      <div class="wb-actions">
        <span class="btn btn-secondary disabled">Bald verfügbar</span>
        <span class="wb-badge">BA 602</span>
      </div>
    </div>

    <!-- 5) Bestände korrigieren -->
    <div class="wb-tile">
      <div class="wb-title">🧮 Bestände korrigieren</div>
      <div class="wb-desc">Inventur-Plus/Minus auf Varianten buchen (BA 702 / 701), optional je Lagerort.</div>
      <div class="wb-actions">
        <span class="btn btn-secondary disabled">Bald verfügbar</span>
        <span class="wb-badge">BA 701/702</span>
      </div>
    </div>
  </div>
</div>
