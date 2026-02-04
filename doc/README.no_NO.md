<!-- Copyright (C) 2024-2026       Kreativitat             <mail@kreativitat.com> -->

# KreaProducts for Dolibarr ERP/CRM

KreaProducts er en avansert modul for produktstyring i [Dolibarr ERP/CRM](https://www.dolibarr.org). Den utvider Produkt-modulen med ernæring, allergener, BOM/tekniske ark, inventar og automatisering av kostnader og lager — laget for servering og detaljhandel som trenger konsistens, sporbarhet og korrekt _food cost_.

## Funksjoner

### Ernæring og allergener

- Næringstabell med automatisk beregning, validering og oppdatering.
- Næringsstoffer propagert mellom foreldre-/barneprodukter, inkludert BOM (MRP) når aktivt.
- Allergenstyring med propagasjon etter prosent av total vekt og merking av spor.
- Støtte for ikke-matprodukter (utelatt fra beregning).

### Produktstruktur og BOM

- Fullstendig produkt-tre (assosiasjoner + BOM/MRP, når aktivt) med hierarkisk navigasjon.
- Detaljert visning av produktsammensetning (komponenter, mengder og delprodukter) med totalisering av **kostpris**.
- Tydelig identifikasjon av relasjoner mellom produkter og tekniske ark, inkludert **kildepakninger** når relevant.
- Omvendt visning (_hvor brukt_): liste over kit/tekniske ark og menyer der varen brukes som komponent, for å vurdere påvirkningen av kostnadsendringer, erstatninger og normalisering av råvarer.
- Demontering-BOM med opprinnelse og relasjoner synlige i teknisk ark.
- Automatisk kaskadereberegning av kostnader basert på komponenter/tekniske ark.
- Støtte for sammenkjedet BOM (BOM-linjer som refererer til en annen BOM) med korrekt propagasjon av kostnader, ernæring og allergener.
- Multiselskap: delte BOM-er (entity=0) tilgjengelig for alle enheter, med prioritet til BOM-en i gjeldende enhet når den finnes.
- Demontering styres per produkt via ekstrafeltet `kreap_dismantle`.

### Korrekte lager- og inventardatoer (fakturadato og verdidato)

Som standard registrerer Dolibarr mange bevegelser **på datoen dokumentet blir postert/validert i systemet** — noe som kanskje ikke samsvarer med den operasjonelle virkeligheten. I miljøer med hyppige innkjøp skaper dette avvik og støy i lageranalysen.

KreaProducts løser denne begrensningen med to viktige automatiseringer:

- **Lagerinngang på fakturadato (leverandører):** produkter bokføres på lager med **fakturadato/mottaksdato**, i stedet for datoen dokumentet registreres i Dolibarr. Dette fjerner avvik når fakturaen registreres flere dager senere.
- **Inventar etter verdidato (retroaktivt):** inventarjusteringen brukes basert på **inventardato (verdidato)**, ikke valideringsdato. Dette gjør det mulig å registrere et inventar med tidligere verdidato (for eksempel en uke tilbake) og holde korrigeringer og rapporter konsistente — noe standardmodulen ikke garanterer.
- **Omregning basert på fysisk opptelling:** lageret beregnes på nytt basert på **opptalt mengde** (qty_stock) når tilgjengelig, med qty_view kun som fallback, for å unngå avvik ved tilbakeførte bevegelser.

### Smart emballasje- og enhetskostnadshåndtering (automatisk demontering)

I serveringsbransjen er det vanlig å kjøpe samme vare i ulike emballasjer — men for _food cost_ er det **reell enhetskost** som teller (f.eks. EUR/L, EUR/kg, EUR/stk).

Typisk eksempel: **olje**. Den kan kjøpes i **10L-, 5L-, 1L-kanner** eller **12x1L-esker**. Hvis disse emballasjene kommer inn som "forskjellige produkter", oppstår raskt inkonsistens i lager og enhetskost.

KreaProducts løser dette via **Dolibarrs BOM-modul (Stykk-lister / Materialark - FM)**:

- Konfigurer en FM for emballasjen (f.eks. _10L-kanne_), og definer konvertering til enhetsproduktet (f.eks. _10x 1L_).
- Deretter vil systemet ved hver innkjøp av slike emballasjer utføre **automatisk demontering** til enhetsproduktet, **uten brukerinngrep**.

Denne prosessen:

- oppretter tilsvarende **lagerbevegelser**,
- beholder **proporsjonal kost** og sporbarhet (opprinnelse -> destinasjon),
- og sørger for at enhetsproduktet er klart til bruk i oppskrifter, inventar og marginberegninger.

### Automatisk oppdatering av kostnader og _food cost_ (kaskade)

KreaProducts automatiserer også oppdatering av **kostpris** og **food cost** for ferdigvarer, basert på deres tekniske ark (BOM/FM).

I praksis:

- hvis en komponent (f.eks. **olje**) får oppdatert innkjøpspris,
- vil alle produkter som bruker komponenten (f.eks. **pommes frites**) få **kostnaden automatisk beregnet på nytt**,
- slik at _food cost_ og marginer alltid reflekterer virkeligheten, uten manuelle justeringer.

Denne funksjonen er spesielt viktig i virksomheter med mange oppskrifter og hyppige innkjøp, der små kostnadsendringer må reflekteres umiddelbart i sluttproduktene.

### Produktivitet og lister

- Forenklet produktliste med mulighet for å skjule elementer.
- Prissimulator (Metrikker og marginer) med test-markup.
- Liste over lagerbevegelser per produkt med **totalt lager**.

## Krav

- Dolibarr >= 19
- PHP >= 7.0
- Påkrevde moduler: Produkter, Lager, Leverandører, BOM/MRP
- Valgfritt: Partier (productbatch)

## Installasjon

1. Kopier modulen til `custom/kreaproducts`.
2. Aktiver i Konfigurasjon -> Moduler/Applikasjoner -> KreaProducts.
3. Juster alternativene på konfigurasjonssiden.
4. Om nødvendig, importer skriptene i `sql/`.

## Konfigurasjon (viktigste konstanter)

| Konstant | Beskrivelse |
| --- | --- |
| `KREAPRODUCTS_DEFAULT_WEIGHT_LABEL` | Enhetsklasse for vekt. |
| `KREAPRODUCTS_NUTRITIONAL_TABLE_TAB` | Vis næringstabellen i fanen for teknisk ark. |
| `KREAPRODUCTS_ENABLE_COPY_AVG_TO_PRODUCT` | Vis velger og knapp for å kopiere gjennomsnittsverdier per 100 g. |
| `KREAPRODUCTS_ENABLE_COPY_ALLERGENS_TO_PRODUCT` | Vis velger og knapp for å kopiere allergener til et annet produkt. |
| `KREAPRODUCTS_AUTO_SYNCH_BUY_PRICE` | Propager kostpris automatisk (kaskadereberegning). |
| `KREAPRODUCTS_ALLERGEN_FULL_THRESHOLD_PCT` | Prosent av total vekt for å regne allergener som tilstede. |
| `KREAPRODUCTS_ALLERGEN_TRACE_THRESHOLD_PCT` | Prosent av total vekt for å markere allergener som spor. |
| `KREAPRODUCTS_STOCK_MOVEMENT_DATA` | Bruk fakturadato for lagerbevegelser. |
| `KREAPRODUCTS_SUPPLIER_MOVE_TIME` | Tid brukt på leverandørfakturabevegelser. |
| `KREAPRODUCTS_INVENTORY_DEFAULT_TIME` | Standardtid ved opprettelse av inventar. |
| `KREAPRODUCTS_INVENTORY_CATEGORY_ROOT` | Rotkategori for inventarvalg. |
| `KREAPRODUCTS_DISMANTLE_BOMTYPE` | BOM-type brukt til demontering. |
| `KREAPRODUCTS_DISMANTLE_WAREHOUSE` | Lager for demonteringsbevegelser. |
| `KREAPRODUCTS_SIM_ENABLE` | Aktiver prissimulatoren. |
| `KREAPRODUCTS_SIM_DEFAULT_MARKUP` | Standard markup for simulatoren. |
| `KREAPRODUCTS_REPLACE_PRODUCT_LIST` | Erstatt standard produktliste. |
| `KREAPRODUCTS_DEBUG_LOG` | Aktiver debug-logger for KreaProducts. |

Merk: allergenterskler er prosenter av total oppskriftsvekt for sluttproduktet.

## Tillatelser

- Ernæring: lese, skrive, slette.
- Allergener: lese, skrive, slette.
- Inventar: se forventede verdier.

## Lisens

- GPL-3.0-or-later (se LICENSE og COPYING).
- Proprietær lisens tilgjengelig for kommersiell bruk eller lukket kildekode; kontakt mail@kreativitat.com.

## Støtte og bidrag

- GitHub: https://github.com/kreativitat
- Website: https://www.kreativitat.com
- Demo: https://dolibarr.kreativitat.com

## Juridisk merknad

Ernærings- og allergenopplysninger legges inn av brukeren eller utledes fra brukerens inndata og er ikke verifisert. De gis kun til informasjonsformål og utgjør ikke medisinsk, kostholds- eller regulatorisk rådgivning. Brukeren er alene ansvarlig for nøyaktighet, merking og overholdelse av gjeldende lovgivning. Denne modulen leveres "som den er", uten garantier av noe slag, uttrykte eller underforståtte, inkludert salgbarhet og egnethet for et bestemt formål. I den grad loven tillater det, er ikke forfattere og distributører ansvarlige for direkte eller indirekte skader som oppstår ved bruk av dataene eller programvaren.

## Skjermbilder

![KreaProducts - Skjerm 1](img/screenshot_1.png)

![KreaProducts - Skjerm 2](img/screenshot_2.png)
