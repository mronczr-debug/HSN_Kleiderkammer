<?php
declare(strict_types=1);

function navItem(string $href, string $label, string $key, string $active): string {
  $cls = 'nav-link' . ($active === $key ? ' active' : '');
  return "<a class=\"$cls\" href=\"$href\">".e($label)."</a>";
}

$base   = rtrim(base_url(), '/');
$active = $active ?? ($_GET['p'] ?? 'home'); // Default jetzt: home
?>
<aside class="sidebar">
  <nav class="nav">
    <?= navItem($base.'/?p=home',       'Start',                  'home',       $active) ?>
    <?= navItem($base.'/?p=materials',  'Materialien',            'materials',  $active) ?>
    <?= navItem($base.'/?p=mitarbeiter','Mitarbeiter',            'mitarbeiter',$active) ?>
    <?= navItem($base.'/?p=bestaende',  'Bestände',               'bestaende',  $active) ?>
    <?= navItem($base.'/?p=bewegungen', 'Warenbewegungen',        'bewegungen', $active) ?>
    <?= navItem($base.'/?p=ausgabe',    'Ausgabe an Mitarbeiter', 'ausgabe',    $active) ?>
    <?= navItem($base.'/?p=reporting',  'Reporting',              'reporting',  $active) ?>
  </nav>
</aside>
