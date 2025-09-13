<?php
// app/pdf/make_delivery_pdf.php
declare(strict_types=1);

/**
 * Erzeugt einen PDF-Ausgabebeleg (Ausgabe oder Rückgabe) für eine Lieferung
 * und speichert die Datei unter /public/files/lieferungen/{LieferNr}.pdf.
 *
 * Voraussetzungen:
 *  - composer: dompdf/dompdf installiert
 *  - Spalten: MitarbeiterLieferungKopf.PdfPath (NVARCHAR) vorhanden
 *
 * Aufruf:
 *   require __DIR__.'/make_delivery_pdf.php';
 *   pdf_make_delivery($pdo, $lieferungId, $absenderNameOptional);
 */

use Dompdf\Dompdf;
use Dompdf\Options;

if (!function_exists('pdf_make_delivery')) {
  function pdf_make_delivery(PDO $pdo, int $lieferungId, ?string $absenderName = null): string
  {
    // 1) Daten laden (Kopf)
    $sqlKopf = "
      SELECT l.LieferungID, l.LieferNr, l.Typ, l.BestellungID, l.MitarbeiterID, l.LieferDatum, l.Status,
             l.RueckgabeGrund, l.CreatedAt, l.CreatedBy,
             k.BestellNr,
             ms.Personalnummer, ms.Nachname, ms.Vorname, ms.Abteilung,
             mt.Bezeichnung AS TypName, mk.Bezeichnung AS KatName
      FROM dbo.MitarbeiterLieferungKopf l
      JOIN dbo.MitarbeiterBestellungKopf k ON k.BestellungID = l.BestellungID
      JOIN dbo.MitarbeiterStamm ms        ON ms.MitarbeiterID = l.MitarbeiterID
      JOIN dbo.MitarbeiterTyp mt          ON mt.TypID = ms.MitarbeiterTypID
      JOIN dbo.MitarbeiterKategorie mk    ON mk.KategorieID = ms.MitarbeiterKategorieID
      WHERE l.LieferungID = :id
    ";
    $st = $pdo->prepare($sqlKopf);
    $st->execute([':id'=>$lieferungId]);
    $kopf = $st->fetch(PDO::FETCH_ASSOC);
    if (!$kopf) {
      throw new RuntimeException('Lieferung nicht gefunden.');
    }

    // 2) Positionen laden
    $sqlPos = "
      SELECT p.PosNr, p.VarianteID, p.Menge,
             v.VariantenBezeichnung,
             m.MaterialName, v.SKU,
             COALESCE(pvt.Farbe,'')   AS Farbe,
             COALESCE(pvt.Groesse,'') AS Groesse
      FROM dbo.MitarbeiterLieferungPos p
      JOIN dbo.MatVarianten v ON v.VarianteID = p.VarianteID
      JOIN dbo.Material m     ON m.MaterialID = v.MaterialID
      LEFT JOIN dbo.vVarAttr_Pivot_Arbeitskleidung pvt ON pvt.VarianteID = v.VarianteID
      WHERE p.LieferungID = :lid
      ORDER BY p.PosNr
    ";
    $sp = $pdo->prepare($sqlPos);
    $sp->execute([':lid'=>$lieferungId]);
    $pos = $sp->fetchAll(PDO::FETCH_ASSOC);

    // 3) Logo laden (Base64)
    $logoPath = realpath(__DIR__.'/../../public/assets/logo.png');
    $logoDataUri = '';
    if ($logoPath && is_file($logoPath)) {
      $bin = @file_get_contents($logoPath);
      if ($bin !== false) $logoDataUri = 'data:image/png;base64,'.base64_encode($bin);
    }

    // 4) Template einbinden (liefert HTML-String)
    $company = [
      'name' => 'BSH Hausgeräte Service Nauen GmbH',
      'addr' => 'Siemensring 5-9, 14641 Nauen'
    ];
    $meta = [
      'isRueckgabe' => ($kopf['Typ'] === 'R'),
      'absender'    => $absenderName ?: ($kopf['CreatedBy'] ?? ''),
      'logo'        => $logoDataUri
    ];

    require __DIR__.'/template_delivery.php';
    $html = pdf_template_delivery($company, $kopf, $pos, $meta);

    // 5) Dompdf initialisieren
    $options = new Options();
    $options->set('isRemoteEnabled', false);
    $options->set('isHtml5ParserEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans'); // für Umlaute sicher
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    // 6) Ordner bereitstellen & Datei speichern
    $outDir = realpath(__DIR__.'/../../public');
    if (!$outDir) { throw new RuntimeException('public/ nicht gefunden.'); }
    $subDir = $outDir.'/files/lieferungen';
    if (!is_dir($subDir)) {
      if (!@mkdir($subDir, 0775, true) && !is_dir($subDir)) {
        throw new RuntimeException('Ordner /public/files/lieferungen konnte nicht erstellt werden.');
      }
    }
    $fileName = preg_replace('~[^A-Za-z0-9_\-]+~', '_', (string)$kopf['LieferNr']).'.pdf';
    $absPath  = $subDir.'/'.$fileName;

    $pdfBytes = $dompdf->output();
    if (@file_put_contents($absPath, $pdfBytes) === false) {
      throw new RuntimeException('PDF konnte nicht geschrieben werden.');
    }

    // 7) Öffentlicher Pfad in DB aktualisieren (relativer Web-Pfad)
    $webPath = '/files/lieferungen/'.$fileName;
    $upd = $pdo->prepare("UPDATE dbo.MitarbeiterLieferungKopf SET PdfPath = :p WHERE LieferungID = :id");
    $upd->execute([':p'=>$webPath, ':id'=>$lieferungId]);

    return $webPath; // z.B. /files/lieferungen/AL-2025-000123.pdf
  }
}
