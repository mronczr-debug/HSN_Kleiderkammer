<?php
declare(strict_types=1);

/**
 * Globales Layout – fixe Sidebar links, fixer Header oben, Content rechts.
 * Anpassung: rechte Karte in .split ist dynamisch breiter (clamp), Content max-breite begrenzt.
 * Erwartet: Helper-Funktionen e(), base_url().
 */

function layout_header(string $title = 'HSN Kleiderkammer', string $active = 'home'): void {
  $base = rtrim(base_url(), '/');
  ?>
  <!doctype html>
  <html lang="de">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> – HSN Kleiderkammer</title>
    <style>
      :root{
        --header-h: 64px;
        --sidebar-w: 280px;

        /* Chrome (dunkler) */
        --header-bg:#111827;           /* nahezu schwarz */
        --header-text:#ffffff;
        --sidebar-bg:#0f172a;          /* dunkles Blau/Anthrazit */
        --sidebar-text:#e5e7eb;
        --sidebar-text-weak:#cbd5e1;
        --sidebar-active-bg:#1f2937;
        --sidebar-hover-bg:#1e293b;

        /* Content */
        --app-bg:#e9edf3;
        --panel:#ffffff;
        --border:#e5e7eb;
        --text:#0f172a;
        --muted:#6b7280;
        --focus:#6366f1;

        --primary:#111827; --primary-contrast:#ffffff;
        --secondary:#e5e7eb;

        --pill-on-bg:#ecfdf5; --pill-on-border:#a7f3d0; --pill-on-text:#065f46;
        --pill-off-bg:#fef2f2; --pill-off-border:#fecaca; --pill-off-text:#991b1b;

        --radius:12px;
      }

      *{box-sizing:border-box}
      html, body { height:100%; }
      body{
        margin:0;
        background:var(--app-bg);
        color:var(--text);
        font-family:system-ui,-apple-system,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
        line-height:1.35;
      }

      /* Fixe Chrome */
      .app-header{
        position:fixed; top:0; left:var(--sidebar-w); right:0; height:var(--header-h);
        display:flex; align-items:center; gap:.8rem; padding:0 16px;
        background:var(--header-bg); color:var(--header-text);
        box-shadow:0 2px 8px rgba(0,0,0,.25); z-index:1000;
      }
      .brand{display:flex;align-items:center;gap:.6rem}
      .brand img{height:28px; width:auto; object-fit:contain}
      .brand-title{font-weight:800; letter-spacing:.2px}

      .sidebar{
        position:fixed; top:0; left:0; bottom:0; width:var(--sidebar-w);
        background:var(--sidebar-bg); color:var(--sidebar-text);
        padding:12px; border-right:1px solid rgba(255,255,255,0.06);
        z-index:999;
      }
      .sidebar .nav{display:flex; flex-direction:column; gap:6px; margin-top:8px}
      .sidebar .nav-link{
        display:block; padding:.65rem .9rem; border-radius:10px; text-decoration:none;
        color:var(--sidebar-text-weak); font-weight:600; font-size:1.06rem;
      }
      .sidebar .nav-link:hover{ background:var(--sidebar-hover-bg); color:var(--sidebar-text); }
      .sidebar .nav-link.active{ background:var(--sidebar-active-bg); color:#fff; }

      /* Content-Container rechts – füllt dynamisch den Platz neben der Sidebar */
      .content{
        position:relative;
        padding:20px 24px 24px 24px;
        padding-top:calc(var(--header-h) + 16px);
        margin-left:var(--sidebar-w);
        min-height:100dvh;
        width:calc(100vw - var(--sidebar-w)); /* nutzt die komplette Breite neben der Sidebar */
      }

      /* Cards / Typografie */
      .card{
        background:var(--panel);
        border:1px solid var(--border);
        border-radius:var(--radius);
        padding:18px;
        box-shadow:0 1px 16px rgba(0,0,0,.05);
        margin-bottom:16px;
      }
      h1{font-size:1.35rem;margin:0 0 .8rem 0}
      h2{font-size:1.15rem;margin:0 0 .6rem 0}
      h3{font-size:1rem;margin:0 0 .35rem 0}
      .muted{color:var(--muted)}
      .hint{color:var(--muted); font-size:.92rem}

      /* Seiten-Grids */
      /**
       * WICHTIG: Rechte Spalte mit clamp() – wächst mit, bleibt aber stets gut bedienbar.
       * min: 480px, bevorzugt ~34vw, max: 700px
       */
      .split{
        display:grid;
        grid-template-columns: minmax(0, 1fr) clamp(480px, 34vw, 700px);
        gap:16px;
      }
      .grid1{display:grid;grid-template-columns:1fr;gap:14px}
      .grid2{display:grid;grid-template-columns:1fr 1fr;gap:14px}
      .row{display:grid;grid-template-columns:1fr 1fr;gap:14px}
      .row-3{display:grid;grid-template-columns:repeat(3,1fr);gap:14px}

      /* Bei schmalen Viewports einspaltig */
      @media (max-width: 1200px){
        .split{grid-template-columns:1fr;}
      }

      /* Tabellen */
      table{width:100%; border-collapse:collapse; margin-top:10px}
      th,td{padding:.55rem .6rem; border-bottom:1px solid var(--border); text-align:left; font-size:.95rem}
      th{background:#f9fafb}
      td .muted{font-size:.9rem}

      /* Formulare – rundere Eingaben, dezente Fokusse */
      label{display:block; font-weight:600; margin:.2rem 0}
      input[type=text], input[type=date], input[type=file], select, textarea{
        width:100%; padding:.62rem .8rem; border:1px solid #d1d5db; border-radius:10px;
        font-size:1rem; background:#fff; outline:none;
        -webkit-appearance:none; -moz-appearance:none; appearance:none;
        background-image:linear-gradient(45deg, transparent 50%, #6b7280 50%),
                         linear-gradient(135deg, #6b7280 50%, transparent 50%),
                         linear-gradient(to right, #d1d5db, #d1d5db);
        background-position:calc(100% - 15px) calc(1em + 2px), calc(100% - 10px) calc(1em + 2px), calc(100% - 2.2rem) 50%;
        background-size:5px 5px, 5px 5px, 1px 1.8em;
        background-repeat:no-repeat;
      }
      select:focus, input:focus, textarea:focus{ border-color:var(--focus); box-shadow:0 0 0 3px rgba(99,102,241,.18) }
      textarea{min-height:90px; resize:vertical}

      .filters{
        display:grid; grid-template-columns:repeat(6,1fr); gap:10px; margin-bottom:10px
      }
      @media (max-width:1100px){ .filters{grid-template-columns:repeat(3,1fr);} }

      /* Buttons / Badges */
      .btn{appearance:none; border:0; border-radius:10px; padding:.7rem 1rem; font-weight:700; cursor:pointer; display:inline-block; text-decoration:none}
      .btn-primary{background:var(--primary); color:var(--primary-contrast)}
      .btn-secondary{background:var(--secondary); color:#111827}
      .btn[disabled]{opacity:.5; pointer-events:none}

      .pill{display:inline-block; padding:.05rem .5rem; border-radius:999px; font-size:.78rem; border:1px solid #d1d5db}
      .pill.on{background:var(--pill-on-bg); color:var(--pill-on-text); border-color:var(--pill-on-border)}
      .pill.off{background:var(--pill-off-bg); color:var(--pill-off-text); border-color:var(--pill-off-border)}

      .alert{padding:.6rem .8rem; border-radius:8px; margin:.6rem 0}
      .alert-success{background:#ecfdf5; border:1px solid #a7f3d0; color:#065f46}
      .alert-error{background:#fef2f2; border:1px solid #fecaca; color:#991b1b}
    </style>
  </head>
  <body>
    <!-- fixe Sidebar -->
    <aside class="sidebar">
      <?php
        // dem Partial die aktive Seite reichen
        $activeTmp = $active; // lokale Sicherung
        $active = $activeTmp;
        require __DIR__ . '/partials/sidebar.php';
      ?>
    </aside>

    <!-- fixer Header -->
    <header class="app-header">
      <div class="brand">
        <img src="<?= e($base) ?>/assets/logo.png" alt="HSN Logo" onerror="this.style.display='none'">
        <div class="brand-title">HSN Kleiderkammer</div>
      </div>
    </header>

    <!-- Content rechts -->
    <main class="content">
  <?php
}

function layout_footer(): void {
  ?>
    </main>
  </body>
  </html>
  <?php
}
