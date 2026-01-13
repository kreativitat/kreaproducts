<!-- Copyright (C) 2024-2026       Kreativitat             <mail@kreativitat.com> -->

# KreaProducts pentru Dolibarr ERP/CRM

KreaProducts este un modul avansat pentru gestionarea produselor în [Dolibarr ERP/CRM](https://www.dolibarr.org). Extinde modulul Produse cu nutriție, alergeni, BOM/fișe tehnice, inventar și automatizări de costuri și stoc — conceput pentru HoReCa și retail care au nevoie de consistență, trasabilitate și _food cost_ corect.

## Funcționalități

### Nutriție și alergeni

- Tabel nutrițional cu calcul, validare și actualizare automată.
- Propagarea nutrienților între produse părinte/copil, inclusiv BOM (MRP) când este activ.
- Gestionarea alergenilor cu propagare în funcție de procentul din greutatea totală și marcarea urmelor.
- Suport pentru produse nealimentare (excluse din calcul).

### Structură produse și BOM

- Arbore complet de produse (asocieri + BOM/MRP, când este activ), cu navigare ierarhică.
- Vizualizare detaliată a compoziției produsului (componente, cantități și subproduse), cu totalizarea **prețului de cost**.
- Identificare clară a relațiilor între produse și fișe tehnice, inclusiv **ambalaje sursă** când este cazul.
- Vedere inversă (_unde este folosit_): listă de kituri/fișe tehnice și meniuri unde articolul este folosit ca component, pentru a evalua impactul schimbărilor de cost, înlocuirilor și normalizării materiilor prime.
- BOM de demontare cu origine și relații vizibile în fișa tehnică.
- Recalcul automat în cascadă al costurilor pe baza componentelor/fișelor tehnice.
- Suport pentru BOM înlănțuită (linii BOM care referă o altă BOM), cu propagare corectă a costurilor, nutriției și alergenilor.
- Multicompany: BOM partajate (entity=0) disponibile în toate entitățile, cu prioritate pentru BOM-ul entității curente când există.
- Demontarea este controlată per produs prin câmpul extra `kreap_dismantle`.

### Date corecte de stoc și inventar (data facturii și data-valorii)

În mod implicit, Dolibarr înregistrează multe mișcări **la data la care documentul este înregistrat/validat în sistem** — ceea ce poate să nu corespundă realității operaționale. În medii cu achiziții frecvente, această diferență creează abateri și zgomot în analiza stocului.

KreaProducts corectează această limitare cu două automatizări esențiale:

- **Intrare în stoc după data facturii (furnizori):** produsele sunt înregistrate în stoc cu **data facturii/data de intrare**, nu data la care documentul este înregistrat în Dolibarr. Acest lucru elimină discrepanțele când factura este introdusă la câteva zile după.
- **Inventar după data-valorii (retroactiv):** ajustarea inventarului se aplică pe baza **datei inventarului (data-valorii)**, nu a datei de validare. Astfel, este posibil să se introducă un inventar cu dată-valorii anterioară (de exemplu, de acum o săptămână) și să se păstreze corecte rapoartele — ceva ce modulul standard nu garantează.
- **Recalcul pe baza inventarului fizic:** stocul este recalculat folosind **cantitatea numărată** (qty_stock) când este disponibilă, cu qty_view doar ca fallback, evitând deviațiile în mișcările retroactive.

### Gestionare inteligentă a ambalajelor și a costului unitar (demontare automată)

În HoReCa, este obișnuit să se cumpere același articol în ambalaje diferite — dar pentru _food cost_ contează **costul unitar real** (ex.: EUR/L, EUR/kg, EUR/buc).

Exemplu tipic: **ulei**. Poate fi cumpărat în **bidoane de 10L, 5L, 1L** sau **cutii 12x1L**. Dacă aceste ambalaje intră în sistem ca "produse diferite", apar rapid inconsistențe în stoc și cost unitar.

KreaProducts rezolvă acest lucru prin modulul **BOM al Dolibarr (Liste de materiale / Fișă de materiale - FM)**:

- Se configurează o FM pentru ambalaj (ex.: _bidon 10L_), definind conversia către produsul unitar (ex.: _10x 1L_).
- Din acel moment, ori de câte ori se înregistrează achiziția acestor ambalaje, sistemul efectuează **demontarea automată** către produsul unitar, **fără intervenția utilizatorului**.

Acest proces:

- creează **mișcările de stoc** corespunzătoare,
- păstrează **costul proporțional** și trasabilitatea (origine -> destinație),
- și asigură că produsul unitar este gata pentru utilizare în rețete, inventar și calcule de marjă.

### Actualizare automată a costurilor și a _food cost_ (în cascadă)

KreaProducts automatizează actualizarea **prețului de cost** și a **food cost** pentru produsele finale, pe baza fișelor tehnice (BOM/FM).

În practică:

- dacă un component (ex.: **ulei**) are prețul de achiziție actualizat,
- toate produsele care folosesc acel component (ex.: **cartofi prăjiți**) au **costul recalculat automat**,
- asigurând că _food cost_ și marjele reflectă mereu realitatea, fără ajustări manuale.

Această funcționalitate este relevantă în special în operațiuni cu multe rețete și achiziții frecvente, unde mici variații de cost trebuie să se reflecte imediat în produsele finale.

### Productivitate și liste

- Listă de produse simplificată cu opțiune de ascundere a elementelor.
- Simulator de prețuri (Metrici și Marje) cu markup de test.
- Listă a mișcărilor de stoc pe produs cu **stoc total**.

## Cerințe

- Dolibarr >= 19
- PHP >= 7.0
- Module obligatorii: Produse, Stoc, Furnizori, BOM/MRP
- Opțional: Loturi (productbatch)

## Instalare

1. Copiați modulul în `custom/kreaproducts`.
2. Activați în Configurare -> Module/Aplicații -> KreaProducts.
3. Ajustați opțiunile în pagina de configurare.
4. Dacă este necesar, importați scripturile din `sql/`.

## Configurare (constante principale)

| Constantă | Descriere |
| --- | --- |
| `KREAPRODUCTS_DEFAULT_WEIGHT_LABEL` | Clasă de unități pentru greutate. |
| `KREAPRODUCTS_NUTRITIONAL_TABLE_TAB` | Afișează tabelul nutrițional în fila fișei tehnice. |
| `KREAPRODUCTS_ENABLE_COPY_AVG_TO_PRODUCT` | Afișează selectorul și butonul pentru copierea valorilor medii per 100 g. |
| `KREAPRODUCTS_ENABLE_COPY_ALLERGENS_TO_PRODUCT` | Afișează selectorul și butonul pentru copierea alergenilor către alt produs. |
| `KREAPRODUCTS_AUTO_SYNCH_BUY_PRICE` | Propagă automat prețul de cost (recalcul în cascadă). |
| `KREAPRODUCTS_ALLERGEN_FULL_THRESHOLD_PCT` | Procentul din greutatea totală pentru a considera alergenii prezenți. |
| `KREAPRODUCTS_ALLERGEN_TRACE_THRESHOLD_PCT` | Procentul din greutatea totală pentru a marca alergenii ca urme. |
| `KREAPRODUCTS_STOCK_MOVEMENT_DATA` | Folosește data facturii pentru mișcările de stoc. |
| `KREAPRODUCTS_SUPPLIER_MOVE_TIME` | Ora aplicată mișcărilor de facturi furnizor. |
| `KREAPRODUCTS_INVENTORY_DEFAULT_TIME` | Ora implicită la crearea inventarului. |
| `KREAPRODUCTS_INVENTORY_CATEGORY_ROOT` | Categoria rădăcină pentru selecția inventarului. |
| `KREAPRODUCTS_DISMANTLE_BOMTYPE` | Tipul de BOM folosit la demontare. |
| `KREAPRODUCTS_DISMANTLE_WAREHOUSE` | Depozit pentru mișcările de demontare. |
| `KREAPRODUCTS_SIM_ENABLE` | Activează simulatorul de prețuri. |
| `KREAPRODUCTS_SIM_DEFAULT_MARKUP` | Markup implicit al simulatorului. |
| `KREAPRODUCTS_REPLACE_PRODUCT_LIST` | Înlocuiește lista standard de produse. |
| `KREAPRODUCTS_DEBUG_LOG` | Activează logurile de debug KreaProducts. |

Notă: pragurile de alergeni sunt procente din greutatea totală a rețetei produsului final.

## Permisiuni

- Nutriție: citire, scriere, ștergere.
- Alergeni: citire, scriere, ștergere.
- Inventar: vedere valori așteptate.

## Licență

- GPL-3.0-or-later (vezi LICENSE și COPYING).
- Licență proprietară disponibilă pentru utilizare comercială sau cod închis; contactați mail@kreativitat.com.

## Suport și contribuții

- GitHub: https://github.com/kreativitat
- Website: https://www.kreativitat.com
- Demo: https://dolibarr.kreativitat.com

## Notă legală

Datele de nutriție și alergeni sunt introduse de utilizator sau derivate din intrările sale și nu sunt verificate. Sunt furnizate doar cu scop informativ și nu constituie sfat medical, dietetic sau de reglementare. Utilizatorul este singurul responsabil pentru acuratețe, etichetare și respectarea legislației aplicabile. Acest modul este furnizat "ca atare", fără garanții de niciun fel, explicite sau implicite, inclusiv de vandabilitate și adecvare pentru un anumit scop. În măsura maximă permisă de lege, autorii și distribuitorii nu sunt răspunzători pentru daune directe sau indirecte rezultate din utilizarea datelor sau a software-ului.

## Capturi de ecran

![KreaProducts - Ecran 1](img/screenshot_1.png)

![KreaProducts - Ecran 2](img/screenshot_2.png)
