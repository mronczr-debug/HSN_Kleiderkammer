/* =========================================================================================
   Arbeitskleidung – Ausgabe an Mitarbeiter (Bestellung/Lieferung)
   - harte Begrenzung bei Rückgaben (nur rückgabefähige Varianten auswählbar)
   - keine Teillieferungen (1 Lieferung pro Bestellung)
   - Buchung per Materialbeleg: 601 (Ausgabe), 602 (Storno WA / Rückgabe) mit Referenz (FIFO)
   - Nummernkreise: AO, RO, AL, RL
   - Protokollierung: CreatedBy (Windows-User) an Köpfen/Belegen
   ========================================================================================= */

-- USE [Arbeitskleidung];
-- GO

/* ---------------------------------------------
   0) Hilfstabelle Nummernkreis (falls noch nicht existent)
   --------------------------------------------- */
IF OBJECT_ID('dbo.Nummernkreis','U') IS NULL
BEGIN
  CREATE TABLE dbo.Nummernkreis(
    Vorgang      NVARCHAR(10) NOT NULL PRIMARY KEY,  -- z.B. 'WE','AO','AL','RO','RL'
    Prefix       NVARCHAR(10) NOT NULL,              -- z.B. 'AO'
    Jahr         INT          NOT NULL,              -- aktuelles Jahr (yyyy)
    Laufnummer   INT          NOT NULL               -- zuletzt vergebene Nummer
  );
END
GO

/* Seed/Upsert für neue Vorgänge */
MERGE dbo.Nummernkreis AS t
USING (VALUES
  (N'AO', N'AO', YEAR(SYSUTCDATETIME()), 0),  -- Ausgabe-Bestellung
  (N'RO', N'RO', YEAR(SYSUTCDATETIME()), 0),  -- Rückgabe-Bestellung
  (N'AL', N'AL', YEAR(SYSUTCDATETIME()), 0),  -- Ausgabe-Lieferung
  (N'RL', N'RL', YEAR(SYSUTCDATETIME()), 0)   -- Rückgabe-Lieferung
) AS s(Vorgang,Prefix,Jahr,Laufnummer)
ON t.Vorgang = s.Vorgang
WHEN NOT MATCHED THEN
  INSERT (Vorgang,Prefix,Jahr,Laufnummer) VALUES (s.Vorgang,s.Prefix,s.Jahr,s.Laufnummer)
WHEN MATCHED AND t.Jahr <> s.Jahr THEN
  UPDATE SET Jahr = s.Jahr, Laufnummer = 0  -- Jahreswechsel automatisch zurücksetzen
;
GO

/* Einfache Nummernkreis-Funktion (falls noch nicht vorhanden).
   Gibt z. B. 'AO-2025-000001' zurück. */
IF OBJECT_ID('dbo.fn_FormatBelegNr','FN') IS NULL
EXEC('
CREATE FUNCTION dbo.fn_FormatBelegNr(@Prefix NVARCHAR(10), @Jahr INT, @Nr INT)
RETURNS NVARCHAR(40) AS
BEGIN
  RETURN CONCAT(@Prefix, N''-'', @Jahr, N''-'', RIGHT(REPLICATE(N''0'',6)+CAST(@Nr AS NVARCHAR(10)),6));
END
');
GO

/* Prozedur: nächste Belegnummer (generisch, nur wenn nicht vorhanden) */
IF OBJECT_ID('dbo.sp_NextBelegNr','P') IS NULL
EXEC('
CREATE PROCEDURE dbo.sp_NextBelegNr
  @Vorgang NVARCHAR(10),
  @BelegNr NVARCHAR(40) OUTPUT
AS
BEGIN
  SET NOCOUNT ON;
  DECLARE @y INT = YEAR(SYSUTCDATETIME());
  DECLARE @prefix NVARCHAR(10), @nr INT;

  BEGIN TRAN;
    IF NOT EXISTS (SELECT 1 FROM dbo.Nummernkreis WHERE Vorgang=@Vorgang)
      RAISERROR(N''Vorgang im Nummernkreis unbekannt.'',16,1);

    UPDATE dbo.Nummernkreis
      SET Laufnummer = CASE WHEN Jahr <> @y THEN 1 ELSE Laufnummer + 1 END,
          Jahr       = CASE WHEN Jahr <> @y THEN @y ELSE Jahr END
      OUTPUT inserted.Prefix, inserted.Jahr, inserted.Laufnummer
      WHERE Vorgang=@Vorgang;

    SELECT TOP(1) @prefix = Prefix, @y = Jahr, @nr = Laufnummer
    FROM dbo.Nummernkreis WHERE Vorgang=@Vorgang;

    SET @BelegNr = dbo.fn_FormatBelegNr(@prefix, @y, @nr);
  COMMIT TRAN;
END
');
GO

/* ---------------------------------------------
   1) Tabellen – Bestellung (Kopf/Pos)
   --------------------------------------------- */
IF OBJECT_ID('dbo.MitarbeiterBestellungKopf','U') IS NULL
BEGIN
  CREATE TABLE dbo.MitarbeiterBestellungKopf(
    BestellungID   INT IDENTITY(1,1) PRIMARY KEY,
    BestellNr      NVARCHAR(40)  NOT NULL UNIQUE,         -- aus AO/RO
    Typ            CHAR(1)       NOT NULL,                -- 'A' = Ausgabe, 'R' = Rückgabe
    MitarbeiterID  INT           NOT NULL 
      REFERENCES dbo.MitarbeiterStamm(MitarbeiterID),
    BestellDatum   DATETIME2(0)  NOT NULL
      CONSTRAINT DF_MBK_BestellDatum DEFAULT (SYSUTCDATETIME()),
    Status         TINYINT       NOT NULL                 -- 0=Erfasst, 2=Abgeschlossen
      CONSTRAINT DF_MBK_Status DEFAULT (0),
    Hinweis        NVARCHAR(200) NULL,
    RueckgabeGrund NVARCHAR(200) NULL,                    -- Pflicht bei Typ = 'R' (App + Trigger)
    CreatedAt      DATETIME2(0)  NOT NULL 
      CONSTRAINT DF_MBK_CreatedAt DEFAULT (SYSUTCDATETIME()),
    CreatedBy      NVARCHAR(256) NULL
  );
  ALTER TABLE dbo.MitarbeiterBestellungKopf
    ADD CONSTRAINT CK_MBK_Typ CHECK (Typ IN (''A'',''R'')),
        CONSTRAINT CK_MBK_Status CHECK (Status IN (0,2));
END
GO

IF OBJECT_ID('dbo.MitarbeiterBestellungPos','U') IS NULL
BEGIN
  CREATE TABLE dbo.MitarbeiterBestellungPos(
    PosID        INT IDENTITY(1,1) PRIMARY KEY,
    BestellungID INT NOT NULL 
      REFERENCES dbo.MitarbeiterBestellungKopf(BestellungID) ON DELETE CASCADE,
    PosNr        SMALLINT     NOT NULL,
    VarianteID   INT          NOT NULL REFERENCES dbo.MatVarianten(VarianteID),
    Menge        DECIMAL(12,3)NOT NULL
  );
  ALTER TABLE dbo.MitarbeiterBestellungPos
    ADD CONSTRAINT CK_MBP_Menge_Pos CHECK (Menge > 0);
  CREATE UNIQUE INDEX UX_MBP_Bestellung_PosNr
    ON dbo.MitarbeiterBestellungPos(BestellungID, PosNr);
  /* Keine Duplikate derselben Variante je Bestellung (erleichtert Prüfungen) */
  CREATE UNIQUE INDEX UX_MBP_Bestellung_Variante
    ON dbo.MitarbeiterBestellungPos(BestellungID, VarianteID);
END
GO

/* Rueckgabegrund als Pflicht bei Typ R – Trigger */
IF OBJECT_ID('dbo.trg_MBK_RueckgabeGrundPflicht','TR') IS NOT NULL
  DROP TRIGGER dbo.trg_MBK_RueckgabeGrundPflicht;
GO
CREATE TRIGGER dbo.trg_MBK_RueckgabeGrundPflicht
ON dbo.MitarbeiterBestellungKopf
AFTER INSERT, UPDATE
AS
BEGIN
  SET NOCOUNT ON;
  IF EXISTS (
    SELECT 1
    FROM inserted i
    WHERE i.Typ = 'R' AND (i.RueckgabeGrund IS NULL OR LTRIM(RTRIM(i.RueckgabeGrund)) = '')
  )
  BEGIN
    RAISERROR(N'Rückgabegrund ist bei Rückgabe-Bestellungen Pflicht.',16,1);
    ROLLBACK TRANSACTION; RETURN;
  END
END
GO

/* ---------------------------------------------
   2) Tabellen – Lieferung (Kopf/Pos)
   --------------------------------------------- */
IF OBJECT_ID('dbo.MitarbeiterLieferungKopf','U') IS NULL
BEGIN
  CREATE TABLE dbo.MitarbeiterLieferungKopf(
    LieferungID    INT IDENTITY(1,1) PRIMARY KEY,
    LieferNr       NVARCHAR(40)  NOT NULL UNIQUE,           -- aus AL/RL
    Typ            CHAR(1)       NOT NULL,                  -- 'A'/'R' (muss zur Bestellung passen)
    BestellungID   INT           NOT NULL 
      REFERENCES dbo.MitarbeiterBestellungKopf(BestellungID),
    MitarbeiterID  INT           NOT NULL 
      REFERENCES dbo.MitarbeiterStamm(MitarbeiterID),
    LieferDatum    DATETIME2(0)  NOT NULL
      CONSTRAINT DF_MLK_LieferDatum DEFAULT (SYSUTCDATETIME()),
    Status         TINYINT       NOT NULL                   -- 0=Erfasst, 1=Gebucht
      CONSTRAINT DF_MLK_Status DEFAULT (0),
    RueckgabeGrund NVARCHAR(200) NULL,                      -- bei 'R' (Vorbelegung aus Bestellung)
    PdfPath        NVARCHAR(260) NULL,
    CreatedAt      DATETIME2(0)  NOT NULL 
      CONSTRAINT DF_MLK_CreatedAt DEFAULT (SYSUTCDATETIME()),
    CreatedBy      NVARCHAR(256) NULL
  );
  ALTER TABLE dbo.MitarbeiterLieferungKopf
    ADD CONSTRAINT CK_MLK_Typ CHECK (Typ IN (''A'',''R'')),
        CONSTRAINT CK_MLK_Status CHECK (Status IN (0,1));
  /* 1 Lieferung pro Bestellung (keine Teillieferungen) */
  CREATE UNIQUE INDEX UX_MLK_Bestellung ON dbo.MitarbeiterLieferungKopf(BestellungID);
END
GO

IF OBJECT_ID('dbo.MitarbeiterLieferungPos','U') IS NULL
BEGIN
  CREATE TABLE dbo.MitarbeiterLieferungPos(
    LPosID      INT IDENTITY(1,1) PRIMARY KEY,
    LieferungID INT NOT NULL 
      REFERENCES dbo.MitarbeiterLieferungKopf(LieferungID) ON DELETE CASCADE,
    PosNr       SMALLINT     NOT NULL,
    VarianteID  INT          NOT NULL REFERENCES dbo.MatVarianten(VarianteID),
    Menge       DECIMAL(12,3)NOT NULL
  );
  ALTER TABLE dbo.MitarbeiterLieferungPos
    ADD CONSTRAINT CK_MLP_Menge_Pos CHECK (Menge > 0);
  CREATE UNIQUE INDEX UX_MLP_Lieferung_PosNr
    ON dbo.MitarbeiterLieferungPos(LieferungID, PosNr);
  CREATE UNIQUE INDEX UX_MLP_Lieferung_Variante
    ON dbo.MitarbeiterLieferungPos(LieferungID, VarianteID);
END
GO

/* ---------------------------------------------
   3) View – Offene Ausgaben pro Mitarbeiter & Variante
      (nur Datensätze mit Offen > 0)
   --------------------------------------------- */
IF OBJECT_ID('dbo.v_Mitarbeiter_AusgabeOffen','V') IS NOT NULL
  DROP VIEW dbo.v_Mitarbeiter_AusgabeOffen;
GO
CREATE VIEW dbo.v_Mitarbeiter_AusgabeOffen
AS
WITH mb AS (
  SELECT
    mb.MitarbeiterID,
    mb.VarianteID,
    SUM(CASE WHEN mb.BewegungsartID = 601 THEN mb.Menge
             WHEN mb.BewegungsartID = 602 THEN -mb.Menge
             ELSE 0 END) AS Offen
  FROM dbo.Materialbeleg mb
  GROUP BY mb.MitarbeiterID, mb.VarianteID
)
SELECT m.MitarbeiterID, m.VarianteID, m.Offen
FROM mb m
WHERE m.MitarbeiterID IS NOT NULL
  AND m.Offen > 0;
GO

/* ---------------------------------------------
   4) Trigger – Harte Begrenzung bei Rückgabe-Bestellung
      - nur Varianten zulassen, die offen > 0 sind
      - Menge <= offene Menge
   --------------------------------------------- */
IF OBJECT_ID('dbo.trg_MBP_RueckgabeNurOffene','TR') IS NOT NULL
  DROP TRIGGER dbo.trg_MBP_RueckgabeNurOffene;
GO
CREATE TRIGGER dbo.trg_MBP_RueckgabeNurOffene
ON dbo.MitarbeiterBestellungPos
AFTER INSERT, UPDATE
AS
BEGIN
  SET NOCOUNT ON;

  /* Nur Rückgabe-Bestellungen prüfen */
  IF NOT EXISTS (
    SELECT 1
    FROM inserted i
    JOIN dbo.MitarbeiterBestellungKopf k ON k.BestellungID = i.BestellungID
    WHERE k.Typ = 'R'
  ) RETURN;

  /* Aggregation je (Bestellung, Variante) aus inserted (wegen UPDATE/Mehrfachzeilen) */
  ;WITH ins AS (
    SELECT i.BestellungID, i.VarianteID, SUM(i.Menge) AS MengeNeu
    FROM inserted i
    GROUP BY i.BestellungID, i.VarianteID
  ),
  k AS (
    SELECT i.BestellungID, k.MitarbeiterID
    FROM ins i
    JOIN dbo.MitarbeiterBestellungKopf k ON k.BestellungID = i.BestellungID
  ),
  chk AS (
    SELECT i.BestellungID, i.VarianteID, i.MengeNeu,
           avo.Offen AS OffeneMenge,
           k.MitarbeiterID
    FROM ins i
    JOIN k ON k.BestellungID = i.BestellungID
    LEFT JOIN dbo.v_Mitarbeiter_AusgabeOffen avo
      ON avo.MitarbeiterID = k.MitarbeiterID AND avo.VarianteID = i.VarianteID
  )
  /* Regel 1: Variante muss offen existieren */
  IF EXISTS (SELECT 1 FROM chk WHERE OffeneMenge IS NULL)
  BEGIN
    RAISERROR(N'Rückgabe nur für Varianten möglich, die der Mitarbeiter offen hat.',16,1);
    ROLLBACK TRANSACTION; RETURN;
  END

  /* Regel 2: Menge <= offene Menge */
  IF EXISTS (SELECT 1 FROM chk WHERE MengeNeu > OffeneMenge)
  BEGIN
    RAISERROR(N'Rückgabemenge überschreitet die offene Menge dieser Variante.',16,1);
    ROLLBACK TRANSACTION; RETURN;
  END
END
GO

/* ---------------------------------------------
   5) Indizes zur Performance (Auswahl & FIFO)
   --------------------------------------------- */
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_MB_Mitarbeiter_Variante_BuchDat' AND object_id = OBJECT_ID('dbo.Materialbeleg'))
BEGIN
  CREATE INDEX IX_MB_Mitarbeiter_Variante_BuchDat
    ON dbo.Materialbeleg(MitarbeiterID, VarianteID, Buchungsdatum, BelegID)
    WHERE MitarbeiterID IS NOT NULL AND BewegungsartID IN (601,602);
END
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_MB_Variante_ForStock' AND object_id = OBJECT_ID('dbo.Materialbeleg'))
BEGIN
  CREATE INDEX IX_MB_Variante_ForStock
    ON dbo.Materialbeleg(VarianteID, BewegungsartID, Buchungsdatum);
END
GO

/* ---------------------------------------------
   6) Buchungs-Prozedur – Ausgabe (601)
   --------------------------------------------- */
IF OBJECT_ID('dbo.sp_Ausgabe_Buchen','P') IS NOT NULL
  DROP PROCEDURE dbo.sp_Ausgabe_Buchen;
GO
CREATE PROCEDURE dbo.sp_Ausgabe_Buchen
  @LieferungID INT
AS
BEGIN
  SET NOCOUNT ON;

  DECLARE @Typ CHAR(1), @Status TINYINT, @BestellungID INT, @MitarbeiterID INT,
          @LieferNr NVARCHAR(40), @LieferDatum DATETIME2(0), @CreatedBy NVARCHAR(256);

  SELECT @Typ = Typ, @Status = Status, @BestellungID = BestellungID, 
         @MitarbeiterID = MitarbeiterID, @LieferNr = LieferNr, 
         @LieferDatum = LieferDatum, @CreatedBy = CreatedBy
  FROM dbo.MitarbeiterLieferungKopf WHERE LieferungID = @LieferungID;

  IF @Typ <> 'A' OR @Status <> 0
  BEGIN
    RAISERROR(N'Lieferung ist nicht vom Typ Ausgabe oder nicht im Status ERFASST.',16,1);
    RETURN;
  END

  DECLARE @BestellStatus TINYINT, @BestellTyp CHAR(1);
  SELECT @BestellStatus = Status, @BestellTyp = Typ
  FROM dbo.MitarbeiterBestellungKopf WHERE BestellungID = @BestellungID;

  IF @BestellTyp <> 'A' OR @BestellStatus <> 0
  BEGIN
    RAISERROR(N'Bestellung ist nicht vom Typ Ausgabe oder nicht im Status ERFASST.',16,1);
    RETURN;
  END

  /* Positionssummen & Bestandsprüfung */
  ;WITH lp AS (
    SELECT VarianteID, SUM(Menge) AS MengeSum
    FROM dbo.MitarbeiterLieferungPos
    WHERE LieferungID = @LieferungID
    GROUP BY VarianteID
  ),
  stock AS (
    SELECT mb.VarianteID, SUM(mb.Menge * b.Richtung) AS Bestand
    FROM dbo.Materialbeleg mb
    JOIN dbo.Bewegungsart b ON b.BewegungsartID = mb.BewegungsartID
    WHERE mb.VarianteID IN (SELECT VarianteID FROM lp)
    GROUP BY mb.VarianteID
  )
  SELECT 1
  FROM lp l
  LEFT JOIN stock s ON s.VarianteID = l.VarianteID
  WHERE COALESCE(s.Bestand,0) < l.MengeSum;

  IF @@ROWCOUNT > 0
  BEGIN
    RAISERROR(N'Bestand nicht ausreichend für mindestens eine Variante.',16,1);
    RETURN;
  END

  BEGIN TRAN;

    /* Materialbeleg 601 erzeugen (Positionsnummern aus Lieferung übernehmen) */
    INSERT INTO dbo.Materialbeleg
      (BelegNr, Position, Buchungsdatum, BewegungsartID, VarianteID, LagerortID, Menge, MitarbeiterID, ReferenzBelegID, Bemerkung, CreatedAt, CreatedBy)
    SELECT
      @LieferNr        AS BelegNr,
      p.PosNr          AS Position,
      @LieferDatum     AS Buchungsdatum,
      601              AS BewegungsartID,
      p.VarianteID,
      NULL             AS LagerortID,
      p.Menge,
      @MitarbeiterID   AS MitarbeiterID,
      NULL             AS ReferenzBelegID,
      NULL             AS Bemerkung,
      SYSUTCDATETIME() AS CreatedAt,
      @CreatedBy       AS CreatedBy
    FROM dbo.MitarbeiterLieferungPos p
    WHERE p.LieferungID = @LieferungID;

    /* Bestellung auf finalen Lieferstand spiegeln */
    DELETE FROM dbo.MitarbeiterBestellungPos WHERE BestellungID = @BestellungID;

    INSERT INTO dbo.MitarbeiterBestellungPos (BestellungID, PosNr, VarianteID, Menge)
    SELECT @BestellungID, p.PosNr, p.VarianteID, p.Menge
    FROM dbo.MitarbeiterLieferungPos p
    WHERE p.LieferungID = @LieferungID;

    /* Status setzen: Lieferung=Gebucht (1), Bestellung=Abgeschlossen (2) */
    UPDATE dbo.MitarbeiterLieferungKopf
      SET Status = 1
      WHERE LieferungID = @LieferungID;

    UPDATE dbo.MitarbeiterBestellungKopf
      SET Status = 2
      WHERE BestellungID = @BestellungID;

  COMMIT TRAN;
END
GO

/* ---------------------------------------------
   7) Buchungs-Prozedur – Rückgabe (602 mit Referenz, FIFO)
   --------------------------------------------- */
IF OBJECT_ID('dbo.sp_Rueckgabe_Buchen','P') IS NOT NULL
  DROP PROCEDURE dbo.sp_Rueckgabe_Buchen;
GO
CREATE PROCEDURE dbo.sp_Rueckgabe_Buchen
  @LieferungID INT
AS
BEGIN
  SET NOCOUNT ON;

  DECLARE @Typ CHAR(1), @Status TINYINT, @BestellungID INT, @MitarbeiterID INT,
          @LieferNr NVARCHAR(40), @LieferDatum DATETIME2(0), @CreatedBy NVARCHAR(256);

  SELECT @Typ = Typ, @Status = Status, @BestellungID = BestellungID, 
         @MitarbeiterID = MitarbeiterID, @LieferNr = LieferNr, 
         @LieferDatum = LieferDatum, @CreatedBy = CreatedBy
  FROM dbo.MitarbeiterLieferungKopf WHERE LieferungID = @LieferungID;

  IF @Typ <> 'R' OR @Status <> 0
  BEGIN
    RAISERROR(N'Lieferung ist nicht vom Typ Rückgabe oder nicht im Status ERFASST.',16,1);
    RETURN;
  END

  DECLARE @BestellStatus TINYINT, @BestellTyp CHAR(1);
  SELECT @BestellStatus = Status, @BestellTyp = Typ
  FROM dbo.MitarbeiterBestellungKopf WHERE BestellungID = @BestellungID;

  IF @BestellTyp <> 'R' OR @BestellStatus <> 0
  BEGIN
    RAISERROR(N'Bestellung ist nicht vom Typ Rückgabe oder nicht im Status ERFASST.',16,1);
    RETURN;
  END

  /* Vorprüfung: Menge je Variante darf offene Menge nicht überschreiten */
  ;WITH lp AS (
    SELECT VarianteID, SUM(Menge) AS MengeSum
    FROM dbo.MitarbeiterLieferungPos
    WHERE LieferungID = @LieferungID
    GROUP BY VarianteID
  )
  SELECT 1
  FROM lp l
  LEFT JOIN dbo.v_Mitarbeiter_AusgabeOffen avo
    ON avo.MitarbeiterID = @MitarbeiterID AND avo.VarianteID = l.VarianteID
  WHERE avo.Offen IS NULL OR l.MengeSum > avo.Offen;

  IF @@ROWCOUNT > 0
  BEGIN
    RAISERROR(N'Rückgabemenge überschreitet offene Menge (oder Variante nicht offen).',16,1);
    RETURN;
  END

  BEGIN TRAN;

    /* FIFO-Verteilung: je Position die Menge auf offene 601-Belege verteilen und 602 mit Referenz schreiben */
    DECLARE cur CURSOR FAST_FORWARD FOR
      SELECT PosNr, VarianteID, Menge
      FROM dbo.MitarbeiterLieferungPos
      WHERE LieferungID = @LieferungID
      ORDER BY PosNr;

    DECLARE @PosNr SMALLINT, @VarianteID INT, @Menge DECIMAL(12,3);
    OPEN cur;
    FETCH NEXT FROM cur INTO @PosNr, @VarianteID, @Menge;

    WHILE @@FETCH_STATUS = 0
    BEGIN
      DECLARE @Rest DECIMAL(12,3) = @Menge;

      /* Tabelle offener 601-Belege für diesen Mitarbeiter/Variante in FIFO-Reihenfolge */
      ;WITH orig AS (
        SELECT mb.BelegID, mb.Menge, mb.Buchungsdatum
        FROM dbo.Materialbeleg mb
        WHERE mb.MitarbeiterID = @MitarbeiterID
          AND mb.VarianteID = @VarianteID
          AND mb.BewegungsartID = 601
      ),
      ret AS (
        SELECT mb.ReferenzBelegID, SUM(mb.Menge) AS RetQty
        FROM dbo.Materialbeleg mb
        WHERE mb.BewegungsartID = 602
          AND mb.MitarbeiterID = @MitarbeiterID
          AND mb.VarianteID = @VarianteID
        GROUP BY mb.ReferenzBelegID
      ),
      fifo AS (
        SELECT o.BelegID,
               o.Menge - COALESCE(r.RetQty,0) AS Rest,
               o.Buchungsdatum
        FROM orig o
        LEFT JOIN ret r ON r.ReferenzBelegID = o.BelegID
        WHERE (o.Menge - COALESCE(r.RetQty,0)) > 0
      )
      SELECT 1;  -- Dummy, um WITH-Scope zu beenden

      /* Wir iterieren in T-SQL, um @Rest auf FIFO-Einträge zu verteilen */
      DECLARE @tbl TABLE(BelegID BIGINT, Rest DECIMAL(12,3), Buchungsdatum DATETIME2(0));
      INSERT INTO @tbl(BelegID, Rest, Buchungsdatum)
      SELECT BelegID, Rest, Buchungsdatum FROM fifo ORDER BY Buchungsdatum, BelegID;

      DECLARE @RefBelegID BIGINT, @RefRest DECIMAL(12,3), @Buch DATETIME2(0);

      WHILE @Rest > 0
      BEGIN
        SELECT TOP(1) @RefBelegID = BelegID, @RefRest = Rest, @Buch = Buchungsdatum
        FROM @tbl WHERE Rest > 0 ORDER BY Buchungsdatum, BelegID;

        IF @RefBelegID IS NULL
        BEGIN
          RAISERROR(N'Interner Fehler: keine offenen 601-Belege mehr, Restmenge > 0.',16,1);
          ROLLBACK TRAN; CLOSE cur; DEALLOCATE cur; RETURN;
        END

        DECLARE @Take DECIMAL(12,3) = CASE WHEN @Rest <= @RefRest THEN @Rest ELSE @RefRest END;

        INSERT INTO dbo.Materialbeleg
          (BelegNr, Position, Buchungsdatum, BewegungsartID, VarianteID, LagerortID, Menge, MitarbeiterID, ReferenzBelegID, Bemerkung, CreatedAt, CreatedBy)
        VALUES
          (@LieferNr, @PosNr, @LieferDatum, 602, @VarianteID, NULL, @Take, @MitarbeiterID, @RefBelegID, N'Rückgabe', SYSUTCDATETIME(), @CreatedBy);

        SET @Rest = @Rest - @Take;
        UPDATE @tbl SET Rest = Rest - @Take WHERE BelegID = @RefBelegID;
      END

      FETCH NEXT FROM cur INTO @PosNr, @VarianteID, @Menge;
    END

    CLOSE cur; DEALLOCATE cur;

    /* Bestellung auf finalen Lieferstand spiegeln */
    DELETE FROM dbo.MitarbeiterBestellungPos WHERE BestellungID = @BestellungID;

    INSERT INTO dbo.MitarbeiterBestellungPos (BestellungID, PosNr, VarianteID, Menge)
    SELECT @BestellungID, p.PosNr, p.VarianteID, p.Menge
    FROM dbo.MitarbeiterLieferungPos p
    WHERE p.LieferungID = @LieferungID;

    /* Status setzen: Lieferung=Gebucht (1), Bestellung=Abgeschlossen (2) */
    UPDATE dbo.MitarbeiterLieferungKopf
      SET Status = 1
      WHERE LieferungID = @LieferungID;

    UPDATE dbo.MitarbeiterBestellungKopf
      SET Status = 2
      WHERE BestellungID = @BestellungID;

  COMMIT TRAN;
END
GO

/* ---------------------------------------------
   8) Komfort-Views für Listen/Reporting (optional)
   --------------------------------------------- */
IF OBJECT_ID('dbo.v_MitarbeiterBestellungen','V') IS NOT NULL
  DROP VIEW dbo.v_MitarbeiterBestellungen;
GO
CREATE VIEW dbo.v_MitarbeiterBestellungen
AS
SELECT
  k.BestellungID, k.BestellNr, k.Typ, k.BestellDatum, k.Status,
  k.MitarbeiterID, ms.Personalnummer,
  CONCAT(ms.Nachname, N', ', ms.Vorname) AS Vollname,
  k.RueckgabeGrund, k.Hinweis, k.CreatedAt, k.CreatedBy
FROM dbo.MitarbeiterBestellungKopf k
JOIN dbo.MitarbeiterStamm ms ON ms.MitarbeiterID = k.MitarbeiterID;
GO

IF OBJECT_ID('dbo.v_MitarbeiterLieferungen','V') IS NOT NULL
  DROP VIEW dbo.v_MitarbeiterLieferungen;
GO
CREATE VIEW dbo.v_MitarbeiterLieferungen
AS
SELECT
  l.LieferungID, l.LieferNr, l.Typ, l.LieferDatum, l.Status,
  l.BestellungID, l.MitarbeiterID, ms.Personalnummer,
  CONCAT(ms.Nachname, N', ', ms.Vorname) AS Vollname,
  l.RueckgabeGrund, l.PdfPath, l.CreatedAt, l.CreatedBy
FROM dbo.MitarbeiterLieferungKopf l
JOIN dbo.MitarbeiterStamm ms ON ms.MitarbeiterID = l.MitarbeiterID;
GO
