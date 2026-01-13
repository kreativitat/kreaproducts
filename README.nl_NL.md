<!-- Copyright (C) 2024-2026       Kreativitat             <mail@kreativitat.com> -->

# KreaProducts voor Dolibarr ERP/CRM

KreaProducts is een geavanceerde module voor productbeheer in [Dolibarr ERP/CRM](https://www.dolibarr.org). Het breidt de Producten-module uit met voeding, allergenen, BOM/technische fiches, inventaris en kosten-/voorraad-automatiseringen — bedoeld voor horeca en retail die consistentie, traceerbaarheid en een correcte _food cost_ nodig hebben.

## Functionaliteiten

### Voeding en allergenen

- Voedingstabel met automatische berekening, validatie en updates.
- Voedingsstoffen worden doorgegeven tussen ouder-/kindproducten, inclusief BOM (MRP) wanneer ingeschakeld.
- Allergenenbeheer met doorgeven op percentage van het totale gewicht en markering van sporen.
- Ondersteuning voor niet-voedingsproducten (uitgesloten van berekening).

### Productstructuur en BOM

- Volledige productboom (associaties + BOM/MRP, wanneer ingeschakeld) met hiërarchische navigatie.
- Gedetailleerde weergave van productsamenstelling (componenten, aantallen en subproducten), met totalisering van de **kostprijs**.
- Duidelijke identificatie van relaties tussen producten en technische fiches, inclusief **bronverpakkingen** waar van toepassing.
- Omgekeerde weergave (_waar gebruikt_): lijst van kits/technische fiches en menu's waarin het artikel als component wordt gebruikt, om de impact van kostenwijzigingen, vervangingen en normalisatie van grondstoffen te evalueren.
- Demontage-BOM met herkomst en relaties zichtbaar op de technische fiche.
- Automatische cascade-herberekening van kosten op basis van componenten/technische fiches.
- Ondersteuning voor geneste BOM's (BOM-regels die naar een andere BOM verwijzen), met correcte doorvoer van kosten, voeding en allergenen.
- Multicompany: gedeelde BOM's (entity=0) beschikbaar voor alle entiteiten, met prioriteit voor de BOM van de huidige entiteit wanneer die bestaat.
- Demontage wordt per product geactiveerd via het extra veld `kreap_dismantle`.

### Correcte voorraad- en inventarisdatums (factuurdatum en waardedatum)

Standaard registreert Dolibarr veel bewegingen **op de datum waarop het document in het systeem wordt geboekt/geverifieerd** — wat niet altijd overeenkomt met de operationele realiteit. In omgevingen met frequente aankopen veroorzaakt dit afwijkingen en ruis in de voorraadanalyse.

KreaProducts verhelpt deze beperking met twee essentiële automatiseringen:

- **Voorraadingang op factuurdatum (leveranciers):** producten worden in voorraad geboekt met de **factuurdatum/ontvangstdatum**, in plaats van de datum waarop het document in Dolibarr wordt ingevoerd. Dit voorkomt verschillen wanneer de factuur pas dagen later wordt geregistreerd.
- **Inventaris op waardedatum (retroactief):** de inventarisaanpassing gebeurt op basis van de **inventarisdatum (waardedatum)** en niet de validatiedatum. Dit maakt het mogelijk een inventaris met een eerdere waardedatum (bijv. een week geleden) in te voeren en correcties en rapporten consistent te houden — iets wat de standaardmodule niet garandeert.
- **Herberekening op basis van fysieke inventaris:** de voorraad wordt herberekend op basis van de **getelde hoeveelheid** (qty_stock) wanneer beschikbaar, met qty_view alleen als fallback, om drift bij teruggedateerde bewegingen te voorkomen.

### Slim verpakkings- en eenheidskostenbeheer (automatische demontage)

In de horeca is het gebruikelijk hetzelfde artikel in verschillende verpakkingen te kopen — maar voor _food cost_ telt de **werkelijke eenheidskost** (bijv. EUR/L, EUR/kg, EUR/stuk).

Typisch voorbeeld: **olie**. Dit kan worden gekocht in **10L-, 5L-, 1L-canisters** of **dozen 12x1L**. Als deze verpakkingen als "verschillende producten" in het systeem komen, ontstaan snel inconsistenties in voorraad en eenheidskost.

KreaProducts lost dit op via de **BOM-module van Dolibarr (Stuklijsten / Materiaalfiche - FM)**:

- Configureer een FM voor de verpakking (bijv. _10L-canister_), waarbij de omzetting naar het eenheidsproduct wordt gedefinieerd (bijv. _10x 1L_).
- Vanaf dat moment voert het systeem bij elke aankoop van die verpakking een **automatische demontage** naar het eenheidsproduct uit, **zonder tussenkomst van de gebruiker**.

Dit proces:

- creëert de bijbehorende **voorraadbewegingen**,
- behoudt de **proportionele kost** en traceerbaarheid (bron -> bestemming),
- en zorgt dat het eenheidsproduct klaar is voor gebruik in recepten, inventaris en marges.

### Automatische kosten- en _food cost_-updates (cascade)

KreaProducts automatiseert ook de update van de **kostprijs** en de **food cost** van eindproducten op basis van hun technische fiches (BOM/FM).

In de praktijk:

- als een component (bijv. **olie**) een bijgewerkte aankoopprijs heeft,
- worden alle producten die dat component gebruiken (bijv. **frietjes**) **automatisch herberekend**,
- zodat _food cost_ en marges altijd de realiteit weerspiegelen, zonder handmatige aanpassingen.

Deze functie is vooral relevant in omgevingen met veel recepten en frequente aankopen, waar kleine kostenwijzigingen meteen in de eindproducten moeten worden doorgevoerd.

### Productiviteit en lijsten

- Vereenvoudigde productlijst met optie om items te verbergen.
- Prijssimulator (Metrieken en Marges) met test-markup.
- Voorraadbewegingslijst per product met **totale voorraad**.

## Vereisten

- Dolibarr >= 19
- PHP >= 7.0
- Vereiste modules: Producten, Voorraad, Leveranciers, BOM/MRP
- Optioneel: Loten (productbatch)

## Installatie

1. Kopieer de module naar `custom/kreaproducts`.
2. Activeer in Configuratie -> Modules/Toepassingen -> KreaProducts.
3. Pas de opties aan op de configuratiepagina.
4. Indien nodig, importeer de scripts in `sql/`.

## Configuratie (belangrijkste constanten)

| Constante | Beschrijving |
| --- | --- |
| `KREAPRODUCTS_DEFAULT_WEIGHT_LABEL` | Eenheidsklasse voor gewicht. |
| `KREAPRODUCTS_NUTRITIONAL_TABLE_TAB` | Toon de voedingstabel op het tabblad technische fiche. |
| `KREAPRODUCTS_ENABLE_COPY_AVG_TO_PRODUCT` | Toon de selector en knop om gemiddelde waarden per 100 g te kopiëren. |
| `KREAPRODUCTS_ENABLE_COPY_ALLERGENS_TO_PRODUCT` | Toon de selector en knop om allergenen naar een ander product te kopiëren. |
| `KREAPRODUCTS_AUTO_SYNCH_BUY_PRICE` | Automatisch de kostprijs doorgeven (cascade-herberekening). |
| `KREAPRODUCTS_ALLERGEN_FULL_THRESHOLD_PCT` | Percentage van het totale gewicht om allergenen als aanwezig te beschouwen. |
| `KREAPRODUCTS_ALLERGEN_TRACE_THRESHOLD_PCT` | Percentage van het totale gewicht om allergenen als sporen te markeren. |
| `KREAPRODUCTS_STOCK_MOVEMENT_DATA` | Factuurdatum gebruiken voor voorraadbewegingen. |
| `KREAPRODUCTS_SUPPLIER_MOVE_TIME` | Tijd toegepast op leveranciersfactuurbewegingen. |
| `KREAPRODUCTS_INVENTORY_DEFAULT_TIME` | Standaardtijd bij het aanmaken van een inventaris. |
| `KREAPRODUCTS_INVENTORY_CATEGORY_ROOT` | Hoofdcategorie voor inventariskeuze. |
| `KREAPRODUCTS_DISMANTLE_BOMTYPE` | BOM-type gebruikt voor demontage. |
| `KREAPRODUCTS_DISMANTLE_WAREHOUSE` | Magazijn voor demontagebewegingen. |
| `KREAPRODUCTS_SIM_ENABLE` | Prijssimulator inschakelen. |
| `KREAPRODUCTS_SIM_DEFAULT_MARKUP` | Standaard markup van de simulator. |
| `KREAPRODUCTS_REPLACE_PRODUCT_LIST` | Standaard productlijst vervangen. |
| `KREAPRODUCTS_DEBUG_LOG` | KreaProducts debuglogs inschakelen. |

Opmerking: allergenen-drempels zijn percentages van het totale receptgewicht van het eindproduct.

## Rechten

- Voeding: lezen, schrijven, verwijderen.
- Allergenen: lezen, schrijven, verwijderen.
- Inventaris: verwachte waarden bekijken.

## Licentie

- GPL-3.0-or-later (zie LICENSE en COPYING).
- Proprietaire licentie beschikbaar voor commercieel gebruik of gesloten broncode; neem contact op met mail@kreativitat.com.

## Ondersteuning en bijdragen

- GitHub: https://github.com/kreativitat
- Website: https://www.kreativitat.com
- Demo: https://dolibarr.kreativitat.com

## Juridische kennisgeving

De voedings- en allergenengegevens worden door de gebruiker ingevoerd of afgeleid van zijn invoer en worden niet geverifieerd. Ze worden uitsluitend ter informatie verstrekt en vormen geen medisch, dietetisch of regulatoir advies. De gebruiker is als enige verantwoordelijk voor nauwkeurigheid, etikettering en naleving van toepasselijke wetgeving. Deze module wordt "zoals hij is" geleverd, zonder enige garantie, expliciet of impliciet, inclusief verkoopbaarheid en geschiktheid voor een bepaald doel. Voor zover wettelijk toegestaan zijn de auteurs en distributeurs niet aansprakelijk voor directe of indirecte schade die voortvloeit uit het gebruik van de gegevens of de software.

## Schermafbeeldingen

![KreaProducts - Scherm 1](img/screenshot_1.png)

![KreaProducts - Scherm 2](img/screenshot_2.png)
