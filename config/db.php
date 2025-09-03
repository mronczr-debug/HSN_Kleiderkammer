<?php
declare(strict_types=1);

// Datenbank Konfiguration
$dsn  = 'sqlsrv:Server=NAUSWIASPSQL01;Database=Arbeitskleidung'; // ggf. tcp:NAUSWIASPSQL01,1433
$user = 'HSN_DB1';
$pass = 'HSNdb1';

// DB Verbindung aufbauen
try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::SQLSRV_ATTR_ENCODING    => PDO::SQLSRV_ENCODING_UTF8,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo "DB-Verbindung fehlgeschlagen: ".htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    exit;
}
