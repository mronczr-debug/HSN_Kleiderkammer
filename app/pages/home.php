<?php
declare(strict_types=1);

/**
 * Home / Start – HSN Kleiderkammer
 * Schlichte, mittig zentrierte Begrüßung mit Logo und Quick-Links.
 *
 * Erwartet: Helpers (e, base_url) und umschließendes Layout (layout_header/-footer).
 */

$base = rtrim(base_url(), '/');
$logo = $base . '/assets/logo.png'; // Datei unter /public/assets/logo.png
?>
<div style="display:flex;align-items:center;justify-content:center;min-height:calc(100vh - 140px);padding:24px;">
  <div class="card" style="max-width:820px;width:100%;text-align:center;padding:48px;">
    <div style="display:flex;flex-direction:column;align-items:center;gap:12px;">
      <img src="<?= e($logo) ?>" alt="HSN Logo"
           style="height:120px;max-width:280px;object-fit:contain;" onerror="this.style.display='none'">
      <h1 style="margin:.5rem 0 0 0;">HSN Kleiderkammer</h1>
      <p class="muted" style="font-size:1.05rem;margin:.25rem 0 1.25rem 0;">
        Herzlich&nbsp;Willkommen in der Kleiderkammer der HSN.
      </p>

      <div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:center;margin-top:.25rem">
        <a class="btn btn-primary" href="<?= e($base) ?>/?p=materials">Zu den Materialien</a>
        <a class="btn btn-secondary" href="<?= e($base) ?>/?p=mitarbeiter">Mitarbeiter öffnen</a>
        <a class="btn btn-secondary" href="<?= e($base) ?>/?p=bestaende">Bestände ansehen</a>
      </div>
    </div>
  </div>
</div>
