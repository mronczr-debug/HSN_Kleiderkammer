<?php
declare(strict_types=1);
session_start();

/**
 * Front Controller / Router
 * - Default-Seite: home
 * - Führt Page zuerst aus (Output-Buffer) → Redirects via header() funktionieren weiterhin
 * - Danach Layout drum herum rendern (layout_header / -footer)
 */

$BASE_DIR = dirname(__DIR__);

// Helpers & DB
require $BASE_DIR . '/lib/helpers.php';
require $BASE_DIR . '/config/db.php';     // stellt $pdo bereit
require $BASE_DIR . '/app/layout.php';    // layout_header(), layout_footer()

// Whitelist der Seiten
$allowed = [
  'home'        => 'home',
  'materials'   => 'materials',
  'mitarbeiter' => 'mitarbeiter',
  'bestaende'   => 'bestaende',
  'bewegungen'  => 'bewegungen',
  'wareneingang'=> 'wareneingang',
  'wareneingang_korrektur'=> 'wareneingang_korrektur',  
  'ausgabe'     => 'ausgabe',
  'ausgabe_bestellung_neu'=> 'ausgabe_bestellung_neu',
  'ausgabe_lieferung'=> 'ausgabe_lieferung',
  'reporting'   => 'reporting',
  'reporting_mitarbeiter'=> 'reporting_mitarbeiter',
  'reporting_belege'=> 'reporting_belege',
  'variant_create'=>'variant_create',
  'dashboard'   => 'home',   // Alias
  'start'       => 'home',   // Alias
];

$p = strtolower((string)($_GET['p'] ?? 'home'));
if (!array_key_exists($p, $allowed)) {
  http_response_code(404);
  $p = 'home'; // Fallback auf home, alternativ echte 404-Seite rendern
}

$pageKey  = $allowed[$p];
$pageFile = $BASE_DIR . '/app/pages/' . $pageKey . '.php';

// Titel mapping
$titles = [
  'home'        => 'Start',
  'materials'   => 'Materialien',
  'mitarbeiter' => 'Mitarbeiter',
  'bestaende'   => 'Bestände',
  'bewegungen'  => 'Warenbewegungen',
  'wareneingang'=> 'Wareneingänge erfassen',
  'wareneingang_korrektur'=> 'Wareneingänge Korrektur',
  'ausgabe'     => 'Ausgabe an Mitarbeiter',
  'reporting'   => 'Reporting',
  'variant_create' => 'Variante anlegen',
  'reporting_belege'=> 'Reporting Materialbelege',
  'reporting_mitarbeiter'=> 'Reporting Mitarbeiter',
];
$title = $titles[$pageKey] ?? 'HSN Kleiderkammer';

/**
 * 1) Seite ausführen und Inhalt puffern
 *    Wichtig: noch KEIN HTML des Layouts ausgeben, damit header('Location: ...') in der Page möglich ist.
 */
ob_start();
if (is_file($pageFile)) {
  require $pageFile;   // darf echo'en
} else {
  echo '<div class="card"><h1>Seite nicht gefunden</h1><p class="muted">Die Seite „'.e($pageKey).'“ existiert (noch) nicht.</p></div>';
}
$pageContent = ob_get_clean();

/**
 * 2) Layout rendern und den gepufferten Inhalt einfügen
 */
layout_header($title, $pageKey);
echo $pageContent;
layout_footer();