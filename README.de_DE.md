<!-- Copyright (C) 2024-2026       Kreativitat             <mail@kreativitat.com> -->

# KreaProducts für Dolibarr ERP/CRM

KreaProducts ist ein fortschrittliches Modul zur Produktverwaltung für das [Dolibarr ERP/CRM](https://www.dolibarr.org). Es erweitert das Produktmodul um Nährwerte, Allergene, BOM/Technische Datenblätter, Inventur sowie Kosten- und Lagerautomatisierungen - gedacht für Gastronomie und Einzelhandel, die Konsistenz, Rückverfolgbarkeit und stets korrekten _food cost_ benötigen.

## Funktionen

### Ernährung und Allergene

- Nährwerttabelle mit automatischer Berechnung, Validierung und Aktualisierung.
- Weitergabe von Nährwerten zwischen Eltern-/Kindprodukten, einschließlich BOM (MRP), wenn aktiviert.
- Allergenverwaltung mit Weitergabe nach Prozentanteil des Gesamtgewichts und Kennzeichnung von Spuren.
- Unterstützung für Nicht-Lebensmittel (vom Berechnen ausgeschlossen).

### Produktstruktur und BOM

- Vollständiger Produktbaum (Assoziationen + BOM/MRP, wenn aktiviert) mit hierarchischer Navigation.
- Detaillierte Ansicht der Produktzusammensetzung (Komponenten, Mengen und Nebenprodukte) mit Summierung des **Einstandspreises**.
- Klare Identifikation von Beziehungen zwischen Produkten und technischen Datenblättern, einschließlich **Quellverpackungen** falls zutreffend.
- Inverse Ansicht (_wo verwendet_): Liste der Kits/technischen Datenblätter und Menüs, in denen der Artikel als Komponente verwendet wird, zur Bewertung der Auswirkungen von Kostenänderungen, Ersatz und Normalisierung von Rohstoffen.
- Demontage-BOM mit Ursprung und Beziehungen sichtbar im technischen Datenblatt.
- Automatische Kaskaden-Neuberechnung der Kosten basierend auf Komponenten/technischen Datenblättern.
- Unterstützung verschachtelter BOMs (BOM-Zeilen, die auf eine andere BOM verweisen), mit korrekter Weitergabe von Kosten, Nährwerten und Allergenen.
- Multicompany: Gemeinsame BOMs (entity=0) sind in allen Entitäten verfügbar, mit Priorität für die BOM der aktuellen Entität, wenn vorhanden.
- Demontage wird pro Produkt über das Extrafeld `kreap_dismantle` aktiviert.

### Korrekte Lager- und Inventardaten (Rechnungsdatum und Wertstellungsdatum)

Standardmäßig erfasst Dolibarr viele Bewegungen **am Datum, an dem das Dokument im System erfasst/validiert wird** - was nicht unbedingt der betrieblichen Realität entspricht. In Umgebungen mit häufigen Einkäufen führt dieser Unterschied zu Abweichungen und Rauschen in der Bestandsanalyse.

KreaProducts behebt diese Einschränkung mit zwei wesentlichen Automatisierungen:

- **Lagerzugang nach Rechnungsdatum (Lieferanten):** Produkte werden mit dem **Rechnungsdatum/Eingangsdatum** ins Lager gebucht, statt mit dem Datum, an dem das Dokument in Dolibarr erfasst wird. Das beseitigt Abweichungen, wenn eine Rechnung erst Tage später erfasst wird.
- **Inventur nach Wertstellungsdatum (rückwirkend):** Die Inventuranpassung erfolgt anhand des **Inventurdatums (Wertstellungsdatum)** und nicht anhand des Validierungsdatums. Dadurch lässt sich eine Inventur mit einem früheren Wertstellungsdatum (z. B. vor einer Woche) erfassen und Korrekturen sowie Berichte bleiben konsistent - etwas, das das Standardmodul nicht garantiert.
- **Neuberechnung nach physischem Inventar:** Der Bestand wird anhand der **gezählten Menge** (qty_stock) neu berechnet, qty_view nur als Fallback - so werden Abweichungen bei rückdatierten Bewegungen vermieden.

### Intelligentes Verpackungs- und Stückkostenmanagement (automatische Demontage)

In der Gastronomie ist es üblich, denselben Artikel in unterschiedlichen Verpackungen zu kaufen - aber für den _food cost_ zählt der **tatsächliche Stückpreis** (z. B. EUR/L, EUR/kg, EUR/Stk).

Typisches Beispiel: **Öl**. Es kann in **10L-, 5L-, 1L-Kanistern** oder **12x1L-Kartons** gekauft werden. Wenn diese Verpackungen als "unterschiedliche Produkte" ins System gelangen, entstehen schnell Inkonsistenzen bei Bestand und Stückpreis.

KreaProducts löst das über das **BOM-Modul von Dolibarr (Stücklisten / Materialblatt - FM)**:

- Für die Verpackung wird eine FM eingerichtet (z. B. _10L-Kanister_) und die Umrechnung auf das Stückprodukt definiert (z. B. _10x 1L_).
- Ab diesem Moment führt das System bei jeder Erfassung eines Einkaufs dieser Verpackungen eine **automatische Demontage** auf das Stückprodukt durch - **ohne Eingriff des Nutzers**.

Dieser Prozess:

- erstellt die entsprechenden **Lagerbewegungen**,
- bewahrt den **proportionalen Kostenanteil** und die Rückverfolgbarkeit (Ursprung -> Ziel),
- und stellt sicher, dass das Stückprodukt für Rezepte, Inventur und Margenberechnungen bereitsteht.

### Automatische Kosten- und _food cost_-Aktualisierung (Kaskade)

KreaProducts automatisiert außerdem die Aktualisierung des **Einstandspreises** und des **food cost** der fertigen Produkte auf Basis ihrer technischen Datenblätter (BOM/FM).

In der Praxis:

- wenn ein Bestandteil (z. B. **Öl**) im Einkaufspreis aktualisiert wird,
- werden alle Produkte, die diesen Bestandteil verwenden (z. B. **Pommes frites**), **automatisch neu kalkuliert**,
- sodass _food cost_ und Margen stets die Realität widerspiegeln, ohne manuelle Anpassungen.

Diese Funktion ist besonders relevant in Betrieben mit vielen Rezepten und häufigen Einkäufen, bei denen kleine Kostenänderungen sofort in den Endprodukten sichtbar sein müssen.

### Produktivität und Listen

- Vereinfachte Produktliste mit Option zum Ausblenden von Artikeln.
- Preis-Simulator (Metriken und Margen) mit Test-Markup.
- Bestandsbewegungsliste pro Produkt mit **Gesamtbestand**.

## Anforderungen

- Dolibarr >= 19
- PHP >= 7.0
- Erforderliche Module: Produkte, Lager, Lieferanten, BOM/MRP
- Optional: Chargen (productbatch)

## Installation

1. Modul nach `custom/kreaproducts` kopieren.
2. Aktivieren unter Konfiguration -> Module/Anwendungen -> KreaProducts.
3. Optionen auf der Konfigurationsseite anpassen.
4. Falls erforderlich, die Skripte in `sql/` importieren.

## Konfiguration (wichtigste Konstanten)

| Konstante | Beschreibung |
| --- | --- |
| `KREAPRODUCTS_DEFAULT_WEIGHT_LABEL` | Einheitsklasse für Gewicht. |
| `KREAPRODUCTS_NUTRITIONAL_TABLE_TAB` | Nährwerttabelle im Tab des technischen Datenblatts anzeigen. |
| `KREAPRODUCTS_ENABLE_COPY_AVG_TO_PRODUCT` | Auswahl und Schaltfläche zum Kopieren der Durchschnittswerte pro 100g anzeigen. |
| `KREAPRODUCTS_ENABLE_COPY_ALLERGENS_TO_PRODUCT` | Auswahl und Schaltfläche zum Kopieren von Allergenen auf ein anderes Produkt anzeigen. |
| `KREAPRODUCTS_AUTO_SYNCH_BUY_PRICE` | Einkaufspreis automatisch weitergeben (Kaskaden-Neuberechnung). |
| `KREAPRODUCTS_ALLERGEN_FULL_THRESHOLD_PCT` | Prozentsatz des Gesamtgewichts, ab dem Allergene als vorhanden gelten. |
| `KREAPRODUCTS_ALLERGEN_TRACE_THRESHOLD_PCT` | Prozentsatz des Gesamtgewichts, ab dem Allergene als Spuren markiert werden. |
| `KREAPRODUCTS_STOCK_MOVEMENT_DATA` | Rechnungsdatum für Lagerbewegungen verwenden. |
| `KREAPRODUCTS_SUPPLIER_MOVE_TIME` | Zeitangabe für Lieferantenrechnungsbewegungen. |
| `KREAPRODUCTS_INVENTORY_DEFAULT_TIME` | Standardzeit beim Erstellen einer Inventur. |
| `KREAPRODUCTS_INVENTORY_CATEGORY_ROOT` | Stammkategorie für die Inventurauswahl. |
| `KREAPRODUCTS_DISMANTLE_BOMTYPE` | BOM-Typ für die Demontage. |
| `KREAPRODUCTS_DISMANTLE_WAREHOUSE` | Lager für Demontagebewegungen. |
| `KREAPRODUCTS_SIM_ENABLE` | Preis-Simulator aktivieren. |
| `KREAPRODUCTS_SIM_DEFAULT_MARKUP` | Standard-Markup des Simulators. |
| `KREAPRODUCTS_REPLACE_PRODUCT_LIST` | Standard-Produktliste ersetzen. |
| `KREAPRODUCTS_DEBUG_LOG` | Debug-Logs für KreaProducts aktivieren. |

Hinweis: Die Allergen-Schwellenwerte sind Prozentsätze des Gesamtgewichts der Rezeptur des Endprodukts.

## Berechtigungen

- Nährwerte: Lesen, Schreiben, Löschen.
- Allergene: Lesen, Schreiben, Löschen.
- Inventur: Erwartete Werte anzeigen.

## Lizenz

- GPL-3.0-or-later (siehe LICENSE und COPYING).
- Proprietäre Lizenz für kommerzielle Nutzung oder Closed-Source verfügbar; Kontakt: mail@kreativitat.com.

## Support und Beiträge

- GitHub: https://github.com/kreativitat
- Website: https://www.kreativitat.com

## Rechtlicher Hinweis

Nährwert- und Allergen-Daten werden vom Benutzer eingegeben oder aus seinen Eingaben abgeleitet und nicht verifiziert. Sie dienen nur zu Informationszwecken und stellen keine medizinische, diätetische oder regulatorische Beratung dar. Der Benutzer ist allein verantwortlich für Genauigkeit, Kennzeichnung und die Einhaltung geltender Gesetze. Dieses Modul wird "wie besehen" bereitgestellt, ohne Gewährleistungen jeglicher Art, ausdrücklich oder stillschweigend, einschließlich der Gewährleistungen der Marktgängigkeit und Eignung für einen bestimmten Zweck. Soweit gesetzlich zulässig, haften die Autoren und Vertriebspartner nicht für direkte oder indirekte Schäden, die aus der Nutzung der Daten oder der Software entstehen.

## Screenshots

![KreaProducts - Screenshot 1](img/screenshot_1.png)

![KreaProducts - Screenshot 2](img/screenshot_2.png)
