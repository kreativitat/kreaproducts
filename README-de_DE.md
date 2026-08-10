<!-- Copyright (C) 2024-2026 Kreativität Works <mail@kreativitat.com> -->

# KreaProducts für Dolibarr ERP/CRM

KreaProducts ist ein erweitertes Produktverwaltungsmodul für [Dolibarr ERP/CRM](https://www.dolibarr.org). Es ergänzt das Produktmodul um Rezepte und technische Datenblätter, Nährwerte, Allergene, Stücklisten und MRP, nachvollziehbare Inventuren, wertdatierte Lagerbewegungen sowie automatische Aktualisierungen von Kosten und Verkaufspreisen. Das Modul richtet sich an Gastronomie, Einzelhandel und Lebensmittelproduktion, die konsistente Produktdaten, zuverlässige Rückverfolgbarkeit und einen präzisen _Food Cost_ benötigen.

KreaProducts bietet außerdem optionale KI-gestützte Vorschläge für Nährwerte und Allergene über OpenAI, Anthropic, OpenRouter oder eine private Ollama-Instanz. Vorschläge bleiben bearbeitbar, Allergenangaben beruhen ausschließlich auf den Produktdaten und ohne ausdrückliche Bestätigung des Benutzers werden keine Daten gespeichert.

## Wichtigste Neuerungen

- Ein einheitlicher Arbeitsbereich für Nährwerte und Allergene mit gemeinsamer Auswahl für eingegebene Daten, berechnete Daten oder Nicht-Lebensmittel.
- Detaillierte Nährwertberechnung je Komponente mit Menge, Gewicht, Nährwertbeitrag, Rezeptgesamtwerten und normierten Werten pro 100 g.
- Gemeinsame Bearbeitungs- und Speicheraktionen sowie ein separates Dialogfenster zum Kopieren von Nährwerten und Allergenen auf ein anderes Produkt.
- Prüfpflichtige KI-Vorschläge mit strukturierten Antworten, verschlüsselten Zugangsdaten für gehostete Anbieter und Netzwerkschutz für Ollama.
- Produktbeschreibung, Zutaten und Zubereitung in Markdown mit automatischer Konvertierung vorhandener HTML-Daten beim Laden.
- Native Inline-Bearbeitung der Produktart, abgestimmt auf die Felder Typ und Gewicht.
- Trigger-sichere API-Validierung einer Lieferantenrechnung oder aller Entwurfsrechnungen eines Lieferanten.
- Verbesserte Behandlung maßgeblicher Kundenrechnungszeiten, zukünftiger Datumswerte und korrigierter Inventurbestände.

## Funktionen

### Nährwerte und Allergene

- Manuelle Eingabe oder automatische Berechnung von Nährwerten und Allergenen.
- Aufschlüsselung nach Komponenten und Durchschnittswerte pro 100 g.
- Weitergabe zwischen übergeordneten Produkten und Komponenten, einschließlich MRP-Stücklisten.
- Verwaltung enthaltener Allergene und möglicher Spuren anhand ihres Anteils am Gesamtgewicht.
- Unterstützung von Nicht-Lebensmitteln, ohne vorhandene Lebensmitteldaten zu löschen.
- Kontrollierte KI-Vorschläge, die vor dem Speichern ausdrücklich bestätigt werden müssen.

### Produkte, Rezepte und Kosten

- Vollständiger Produktbaum für Zuordnungen, Stücklisten und Unterprodukte.
- Rückwärtsansicht zur Ermittlung aller Rezepte und Produkte, die eine Komponente verwenden.
- Automatische kaskadierende Neuberechnung der Kosten fertiger Produkte.
- Optionale Synchronisierung des Verkaufspreises anhand von Kosten und konfiguriertem Aufschlag.
- Automatische Zerlegung eingekaufter Verpackungseinheiten in verwendbare Einheiten mit Lagerbewegungen und Rückverfolgbarkeit.

### Lager und Inventur

- Datierung von Lieferantenzugängen nach Rechnungs- oder Wareneingangsdatum.
- Kundenbewegungen behalten Datum und Uhrzeit der maßgeblichen Rechnung.
- Wertdatierte Inventuren mit protokollierten Korrekturen und konsistenter Rekonstruktion späterer Bewegungen.
- API-Validierung von Lieferantenrechnungen mit Prüfung von Mandant, Lager und Dolibarr-Berechtigungen.

## Voraussetzungen

- Dolibarr 19 oder neuer.
- PHP 7.3 oder neuer.
- MySQL oder MariaDB.
- Erforderliche Module: Produkte, Lager, Lieferanten, Stücklisten, MRP und Cron.
- Optionales Modul: Chargen/Serien (`productbatch`).

## Lizenz und Support

- GPL-3.0-or-later.
- Website: https://www.kreativitat.com
- Demo: https://dolibarr.kreativitat.com
- Support: mail@kreativitat.com
