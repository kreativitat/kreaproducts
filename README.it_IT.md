<!-- Copyright (C) 2024-2026       Kreativitat             <mail@kreativitat.com> -->

# KreaProducts per Dolibarr ERP/CRM

KreaProducts è un modulo avanzato per la gestione dei prodotti in [Dolibarr ERP/CRM](https://www.dolibarr.org). Estende il modulo Prodotti con nutrizione, allergeni, BOM/Schede Tecniche, inventario e automazioni di costi e stock - pensato per ristorazione e retail che richiedono coerenza, tracciabilità e _food cost_ sempre corretto.

## Funzionalità

### Nutrizione e allergeni

- Tabella nutrizionale con calcolo, validazione e aggiornamento automatico.
- Propagazione dei nutrienti tra prodotti padre/figlio, inclusa BOM (MRP) quando attiva.
- Gestione degli allergeni con propagazione in percentuale del peso totale e marcatura delle tracce.
- Supporto per prodotti non alimentari (esclusi dal calcolo).

### Struttura prodotti e BOM

- Albero completo dei prodotti (associazioni + BOM/MRP, quando attivo), con navigazione gerarchica.
- Visualizzazione dettagliata della composizione del prodotto (componenti, quantità e sottoprodotti), con totalizzazione del **prezzo di costo**.
- Identificazione chiara delle relazioni tra prodotti e schede tecniche, includendo **imballaggi di origine** quando applicabile.
- Vista inversa (_dove è utilizzato_): elenco dei kit/schede tecniche e menu in cui l'articolo entra come componente, consentendo di valutare l'impatto di variazioni di costo, sostituzioni e normalizzazione delle materie prime.
- BOM di smontaggio con origine e relazioni visibili nella scheda tecnica.
- Ricalcolo automatico dei costi in cascata basato su componenti/schede tecniche.
- Supporto alle BOM annidate (righe BOM che referenziano un'altra BOM), con propagazione corretta di costi, nutrizione e allergeni.
- Multi-azienda: BOM condivise (entity=0) disponibili per tutte le entità, con priorità alla BOM dell'entità corrente quando presente.
- Lo smontaggio si attiva per prodotto tramite il campo extra `kreap_dismantle`.

### Date corrette di stock e inventario (data fattura e data-valuta)

Di default, Dolibarr registra molti movimenti **alla data in cui il documento è inserito/validato nel sistema** - il che può non coincidere con la realtà operativa. In ambienti con acquisti frequenti, questa differenza crea scostamenti e rumore nell'analisi di stock.

KreaProducts corregge questa limitazione con due automazioni essenziali:

- **Entrata di stock per data fattura (fornitori):** i prodotti vengono registrati in stock con la **data della fattura/data di entrata**, invece della data in cui il documento viene inserito in Dolibarr. Questo elimina discrepanze quando la fattura viene registrata giorni dopo.
- **Inventario per data-valuta (retroattivo):** l'aggiustamento dell'inventario viene applicato in base alla **data dell'inventario (data-valuta)**, e non alla data di validazione. In questo modo è possibile registrare un inventario con data-valuta precedente (ad esempio, di una settimana fa) e garantire che correzioni e report rimangano coerenti - cosa che il modulo standard non garantisce.
- **Ricalcolo su inventario fisico:** lo stock viene ricalcolato usando la **quantità contata** (qty_stock) quando disponibile, con qty_view solo come fallback, evitando scostamenti sui movimenti retrodatati.

### Gestione intelligente degli imballaggi e costo unitario (smontaggio automatico)

Nella ristorazione, è comune acquistare lo stesso articolo in imballaggi diversi - ma per il _food cost_ conta il **costo unitario reale** (es. EUR/L, EUR/kg, EUR/unità).

Esempio tipico: **olio**. Può essere acquistato in **taniche da 10L, 5L, 1L** o **scatole 12x1L**. Se questi imballaggi entrano nel sistema come "prodotti diversi", rapidamente emergono incoerenze di stock e costo per unità.

KreaProducts risolve questo problema tramite il modulo **BOM di Dolibarr (Liste Materiali / Scheda Materiali - FM)**:

- Si configura una FM per l'imballaggio (es. _tanica 10L_), definendo la conversione al prodotto unitario (es. _10x 1L_).
- Da quel momento, ogni volta che viene registrato l'acquisto di uno di questi imballaggi, il sistema esegue lo **smontaggio automatico** verso il prodotto unitario, **senza intervento dell'utente**.

Questo processo:

- crea i **movimenti di stock** corrispondenti,
- mantiene il **costo proporzionale** e la tracciabilità (origine -> destinazione),
- e garantisce che il prodotto unitario sia pronto per l'uso in ricette, inventario e calcoli di margine.

### Aggiornamento automatico dei costi e _food cost_ (in cascata)

KreaProducts automatizza anche l'aggiornamento del **prezzo di costo** e del **food cost** dei prodotti finiti, in base alle rispettive schede tecniche (BOM/FM).

In pratica:

- se un componente (es. **olio**) ha il prezzo di acquisto aggiornato,
- tutti i prodotti che utilizzano quel componente (es. **patatine fritte**) hanno il **costo ricalcolato automaticamente**,
- garantendo che _food cost_ e margini riflettano sempre la realtà, senza aggiustamenti manuali.

Questa funzionalità è particolarmente rilevante in operazioni con molte ricette e acquisti frequenti, dove piccole variazioni di costo devono riflettersi immediatamente nei prodotti finali.

### Produttività e liste

- Lista prodotti semplificata con opzione per nascondere elementi.
- Simulatore di prezzi (Metriche e Margini) con markup di test.
- Lista movimenti stock per prodotto con **stock totale**.

## Requisiti

- Dolibarr >= 19
- PHP >= 7.0
- Moduli obbligatori: Prodotti, Stock, Fornitori, BOM/MRP
- Opzionale: Lotti (productbatch)

## Installazione

1. Copiare il modulo in `custom/kreaproducts`.
2. Attivarlo in Configurazione -> Moduli/Applicazioni -> KreaProducts.
3. Regolare le opzioni nella pagina di configurazione.
4. Se necessario, importare gli script in `sql/`.

## Configurazione (principali costanti)

| Costante | Descrizione |
| --- | --- |
| `KREAPRODUCTS_DEFAULT_WEIGHT_LABEL` | Classe di unità per il peso. |
| `KREAPRODUCTS_NUTRITIONAL_TABLE_TAB` | Mostrare la tabella nutrizionale nella scheda tecnica. |
| `KREAPRODUCTS_ENABLE_COPY_AVG_TO_PRODUCT` | Mostrare il selettore e il pulsante per copiare i valori medi per 100g. |
| `KREAPRODUCTS_ENABLE_COPY_ALLERGENS_TO_PRODUCT` | Mostrare il selettore e il pulsante per copiare gli allergeni su un altro prodotto. |
| `KREAPRODUCTS_AUTO_SYNCH_BUY_PRICE` | Propagare automaticamente il prezzo di costo (ricalcolo in cascata). |
| `KREAPRODUCTS_ALLERGEN_FULL_THRESHOLD_PCT` | Percentuale del peso totale per considerare gli allergeni presenti. |
| `KREAPRODUCTS_ALLERGEN_TRACE_THRESHOLD_PCT` | Percentuale del peso totale per marcare gli allergeni come tracce. |
| `KREAPRODUCTS_STOCK_MOVEMENT_DATA` | Usare la data della fattura nei movimenti di stock. |
| `KREAPRODUCTS_SUPPLIER_MOVE_TIME` | Ora applicata ai movimenti di fatture fornitori. |
| `KREAPRODUCTS_INVENTORY_DEFAULT_TIME` | Ora predefinita alla creazione dell'inventario. |
| `KREAPRODUCTS_INVENTORY_CATEGORY_ROOT` | Categoria radice per la selezione dell'inventario. |
| `KREAPRODUCTS_DISMANTLE_BOMTYPE` | Tipo di BOM usato per lo smontaggio. |
| `KREAPRODUCTS_DISMANTLE_WAREHOUSE` | Magazzino per i movimenti di smontaggio. |
| `KREAPRODUCTS_SIM_ENABLE` | Attivare il simulatore di prezzi. |
| `KREAPRODUCTS_SIM_DEFAULT_MARKUP` | Markup predefinito del simulatore. |
| `KREAPRODUCTS_REPLACE_PRODUCT_LIST` | Sostituire la lista standard dei prodotti. |
| `KREAPRODUCTS_DEBUG_LOG` | Attivare i log di debug di KreaProducts. |

Nota: le soglie degli allergeni sono percentuali del peso totale della ricetta del prodotto finale.

## Permessi

- Nutrizione: lettura, scrittura, rimozione.
- Allergeni: lettura, scrittura, rimozione.
- Inventario: vedere i valori attesi.

## Licenza

- GPL-3.0-or-later (vedi LICENSE e COPYING).
- Licenza proprietaria disponibile per uso commerciale o codice chiuso; contattare mail@kreativitat.com.

## Supporto e contributi

- GitHub: https://github.com/kreativitat
- Website: https://www.kreativitat.com

## Avviso legale

I dati di nutrizione e allergeni sono inseriti dall'utente o derivati dai suoi input e non sono verificati. Sono forniti solo a scopo informativo e non costituiscono consulenza medica, dietetica o normativa. L'utente è l'unico responsabile dell'accuratezza, dell'etichettatura e della conformità alle leggi applicabili. Questo modulo è fornito "così com'è", senza garanzie di alcun tipo, espresse o implicite, incluse le garanzie di commerciabilità e idoneità a uno scopo specifico. Nella massima misura consentita dalla legge, gli autori e i distributori non sono responsabili per eventuali danni diretti o indiretti derivanti dall'uso dei dati o del software.

## Screenshot

![KreaProducts - Schermata 1](img/screenshot_1.png)

![KreaProducts - Schermata 2](img/screenshot_2.png)
