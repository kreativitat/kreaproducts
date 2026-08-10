<!-- Copyright (C) 2024-2026 Kreativität Works <mail@kreativitat.com> -->

# KreaProducts per Dolibarr ERP/CRM

KreaProducts è un modulo avanzato per la gestione dei prodotti in [Dolibarr ERP/CRM](https://www.dolibarr.org). Estende il modulo Prodotti con ricette e schede tecniche, valori nutrizionali, allergeni, distinte base e MRP, inventari tracciabili, movimenti di magazzino con data di competenza e aggiornamenti automatici dei costi e dei prezzi di vendita. È pensato per ristorazione, vendita al dettaglio e produzione alimentare, dove sono essenziali dati coerenti, tracciabilità affidabile e un _food cost_ preciso.

KreaProducts offre inoltre suggerimenti opzionali assistiti dall'IA per valori nutrizionali e allergeni tramite OpenAI, Anthropic, OpenRouter o un'istanza Ollama privata. I suggerimenti restano modificabili, le dichiarazioni sugli allergeni si basano esclusivamente sui dati del prodotto e nulla viene salvato senza la conferma esplicita dell'utente.

## Principali novità

- Un unico spazio coerente per Nutrizione e allergeni, con un selettore condiviso per dati inseriti, dati calcolati o prodotti non alimentari.
- Calcolo nutrizionale dettagliato per componente, con quantità, peso, contributo nutrizionale, totali della ricetta e valori normalizzati per 100 g.
- Azioni comuni di modifica e salvataggio e una finestra dedicata per copiare nutrizione e allergeni in un altro prodotto.
- Suggerimenti IA soggetti a revisione, con risposte strutturate, credenziali cifrate per i fornitori ospitati e protezioni di rete per Ollama.
- Descrizione del prodotto, ingredienti e preparazione in Markdown, con conversione automatica dei dati HTML esistenti al caricamento.
- Modifica in linea nativa della natura del prodotto, coerente con i campi Tipo e Peso.
- Validazione API sicura rispetto ai trigger per una fattura fornitore o per tutte le fatture in bozza di un fornitore.
- Gestione migliorata della data e dell'ora effettive delle fatture cliente, della tolleranza per date future e della ricostruzione degli inventari corretti.

## Funzionalità

### Nutrizione e allergeni

- Inserimento manuale o calcolo automatico dei valori nutrizionali e degli allergeni.
- Dettaglio per componente e valori medi per 100 g.
- Propagazione tra prodotti principali e componenti, comprese le distinte MRP.
- Gestione degli allergeni presenti e delle tracce in base alla percentuale sul peso totale.
- Supporto dei prodotti non alimentari senza eliminare i dati alimentari già salvati.
- Suggerimenti IA controllati e confermati esplicitamente prima del salvataggio.

### Prodotti, ricette e costi

- Albero completo di associazioni, distinte base e sottoprodotti.
- Vista inversa per individuare ricette e prodotti che utilizzano un componente.
- Ricalcolo automatico a cascata del costo dei prodotti finiti.
- Sincronizzazione opzionale del prezzo di vendita in base al costo e al ricarico configurato.
- Disassemblaggio automatico delle confezioni acquistate in unità utilizzabili, con movimenti di magazzino e tracciabilità.

### Magazzino e inventario

- Datazione dei carichi da fornitore secondo la data della fattura o di ricezione.
- I movimenti cliente mantengono la data e l'ora effettive della fattura.
- Inventari con data di competenza, correzioni registrate e ricostruzione coerente dei movimenti successivi.
- Validazione API delle fatture fornitore con controllo di entità, magazzino e autorizzazioni Dolibarr.

## Requisiti

- Dolibarr 19 o versione successiva.
- PHP 7.3 o versione successiva.
- MySQL o MariaDB.
- Moduli richiesti: Prodotti, Magazzino, Fornitori, Distinte base, MRP e Cron.
- Modulo opzionale: Lotti/serie (`productbatch`).

## Licenza e assistenza

- GPL-3.0-or-later.
- Sito web: https://www.kreativitat.com
- Demo: https://dolibarr.kreativitat.com
- Assistenza: mail@kreativitat.com
