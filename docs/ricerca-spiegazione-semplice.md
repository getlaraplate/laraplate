# Come funziona oggi la ricerca di Laraplate

Spiegazione in linguaggio semplice del percorso di una richiesta API di ricerca, dal parametro `qs`
alla lista di risultati. Scritto in italiano perché è un documento di studio, non una specifica.

Fuori dallo scopo di questo documento: il RAG della documentazione (l'assistente che risponde
leggendo i file markdown). Qui si parla solo della ricerca sui **dati dell'applicazione**.

Riferimenti tecnici precisi, in inglese:
`laraplate/Modules/Core/docs/rag/SEARCH_RETRIEVAL_PIPELINE.md` e
`laraplate/Modules/Core/docs/rag/SEARCH_MATCHING_DEVELOPER.md`.

---

## 1. L'idea in una frase

Una ricerca non è una query sola. È un piano che sceglie fino a tre modi diversi di cercare, esegue
quelli scelti, mescola le tre classifiche in una sola e infine riordina i primi risultati
guardandoli meglio.

---

## 2. Prima però: come i dati entrano nell'indice

Serve saperlo, altrimenti la parte vettoriale non ha senso. Quando salvi un record:

```
salvi un Content
   ↓ Core emette l'evento ModelRequiresIndexing
   ↓ chi vuole fare qualcosa prima si prenota:
       AI dice "devo calcolare gli embedding"  (addRequiredPreProcessing)
       AI dice "devo tradurre"
   ↓ ogni lavoro finito segnala: ModelPreProcessingCompleted
   ↓ quando tutte le prenotazioni sono chiuse
   ↓ il documento viene spedito a Elasticsearch
```

Due cose da ricordare:

- Core non conosce il modulo AI. Se AI non c'è, un listener di riserva indicizza subito, senza
  vettori. Nessun `if (ai_attivo)` sparso nel codice.
- Se il calcolo dei vettori fallisce per sempre, il documento viene indicizzato lo stesso, solo
  keyword. Meglio un documento trovabile a metà che un documento invisibile.

**Embedding** vuol dire: prendo il testo e lo trasformo in una lista di 384 numeri. Testi che
parlano della stessa cosa producono liste di numeri vicine fra loro, anche se usano parole diverse.
È così che la ricerca "semantica" trova `annullare un ordine` cercando `come disdico un acquisto`.

---

## 3. Il percorso di una richiesta

Esempio di chiamata:

```http
GET /app/cms/contents?qs=fatture+fornitori+scadute&mode=auto&matching=balanced
```

`mode` decide la strada:

| `mode` | Cosa succede |
|--------|--------------|
| `auto` (default) | usa la pipeline orchestrata se il motore la supporta, altrimenti ricerca semplice |
| `orchestrated` | forza la pipeline orchestrata |
| `basic` | ricerca Scout semplice, niente orchestrazione |

Poi succedono cinque cose, in quest'ordine.

### Passo 1: capire la query

Due componenti indipendenti lavorano sul testo:

- il **parser della sintassi** separa il testo libero dai termini obbligatori (`+milano`) e dalle
  frasi esatte (`"mario rossi"`);
- l'**analizzatore** classifica ogni parola: parola normale, numero, email, UUID, codice, acronimo.

Esempio su `ACME INV-1042 fattura`:

| Token | Tipo | Conseguenza |
|-------|------|-------------|
| `ACME` | acronimo | protetto, niente correzione refusi |
| `INV-1042` | codice strutturato | protetto, obbligatorio |
| `fattura` | parola | può essere corretta, può essere sacrificata |

Regola: se c'è anche un solo token protetto, la query diventa conservativa. Un codice non deve mai
essere il termine che il sistema butta via per allargare i risultati.

### Passo 2: fare il piano

Il **planner** decide quali strategie eseguire. Nella versione senza AI sono due righe di regole:

```php
$is_short    = lunghezza query < 20 caratteri;
$has_numbers = la query contiene una cifra;
$use_vector  = config("core.search.vector.enabled") && ! $has_numbers;
```

Da cui:

| Situazione | Strategie eseguite |
|------------|--------------------|
| vettoriale spento | solo `keyword` |
| query con una cifra dentro | solo `keyword` |
| motore senza kNN | solo `keyword` |
| modulo AI assente | solo `keyword` |
| caso normale | `keyword` + `vector` + `hybrid` |

**O una o tre, mai due.** `hybrid` esiste solo se esistono le altre due. E le tre chiamate sono
**sequenziali**, una dopo l'altra: non c'è parallelismo.

Quando una strategia non gira, i pesi delle altre vengono rinormalizzati per tornare a sommare 1.0.
Con la sola keyword il peso diventa 1.0 e la fusione non fa nulla di interessante.

### Passo 3: trasformare la query in numeri

Solo se il piano prevede il vettoriale:

```php
if (! $this->app->bound(ITextEmbedder::class)) {
    return null;   // niente vettore, si va di sola keyword
}
```

`ITextEmbedder` è registrato **solo** dal modulo AI. Quindi per avere la ricerca semantica servono
due cose insieme: il setting `core.search.vector.enabled` a true **e** il modulo AI installato e attivo.

Attenzione a non confondere: senza AI non perdi la tokenizzazione del testo (quella la fa
Elasticsearch e il codice in Core), perdi solo la traduzione della query in numeri.

Degli altri tre contratti che AI sostituisce, tutti hanno un ripiego in Core:

| Contratto | Senza AI |
|-----------|----------|
| `ITextEmbedder` | non esiste ripiego: niente vettoriale |
| `IReranker` | riordino euristico in PHP puro |
| `ISearchPlanner` | piano a regole |
| `IQueryIntentParser` | intento base |

### Passo 4: eseguire e fondere

Le tre strategie producono tre classifiche diverse. Il problema: i loro punteggi non sono
confrontabili. BM25 (il punteggio lessicale) può valere 14.7, la similarità coseno vale 0.83. Non
puoi sommarli.

Laraplate fa tre cose insieme:

```
1. normalizza i punteggi di ogni strategia su una scala 0-1 (min-max)
2. somma i punteggi normalizzati, ciascuno per il suo peso
3. aggiunge un termine che guarda la POSIZIONE invece del punteggio: 1 / (60 + posizione)
4. aggiunge un bonus se più strategie hanno trovato lo stesso documento
```

Formula reale:

```
punteggio = Σ (punteggio_normalizzato × peso)          pesi 0.35 / 0.35 / 0.30
          + Σ (1 / (60 + posizione)) × 0.25            questo è RRF
          + 0.15 × (quante strategie l'hanno trovato / quante ne sono girate)
```

Nota importante, perché è facile raccontarselo male: **non è solo RRF**. RRF è il termine basato
sulla posizione e pesa 0.25. Il resto è una media pesata dei punteggi normalizzati. I due
meccanismi si compensano: RRF protegge dalle scale incomparabili, la media pesata conserva la
distanza reale fra il primo e il decimo risultato.

Esempio. Documento A: primo in keyword, terzo in vector. Documento B: primo in vector, assente in
keyword.

```
A: termine RRF = 1/61 + 1/63 = 0.0323   e prende il bonus accordo
B: termine RRF = 1/61        = 0.0164   niente bonus
```

A parità di punteggi normalizzati, A vince perché due strategie sono d'accordo su di lui.

### Passo 5: riordinare i primi

Il **reranker** riceve i primi 30 risultati della lista fusa, con il testo vero dei documenti, e
dà a ognuno un voto da 0 a 1. Il punteggio finale è una miscela:

```
finale = (punteggio_fuso × 0.4) + (voto_reranker × max_punteggio × 0.6)
```

Lo 0.6 è l'impostazione `search.reranker.weight` (gruppo `search` in Filament > Settings): 0 tiene
l'ordine fuso, 1 lascia decidere solo il reranker.

Due dettagli:

- il reranker lavora sulla lista **già fusa**, non sulle tre liste separate. Non decide lui quale
  strategia aveva ragione: quella decisione l'ha già presa la fusione;
- se il reranker è irraggiungibile, l'errore viene catturato, si tiene l'ordine fuso e nella
  risposta trovi `meta.reranked = false`. La ricerca non fallisce mai per colpa del reranker.

---

## 4. Il parametro `matching`

Serve a una cosa sola: decidere quanto è permissiva la parte **testuale**.

Muove due manopole indipendenti:

- **copertura**: quante parole significative devono comparire nel documento;
- **tolleranza refusi**: quante parole possono avere un errore di battitura, e quanto grande.

Su `ricetta torta cioccolato senza glutine` (5 parole):

| Profilo | Copertura | Parole con refuso ammesse |
|---------|-----------|---------------------------|
| `strict` | 100%, tutte e 5 | 1 |
| `balanced` | 65%, ne bastano 4 | 2 |
| `tolerant` | 55%, ne bastano 3 | 3 |

`auto` sceglie da solo: conservativo se la query sembra fatta di parole chiave, più permissivo se
ci sono almeno due stopword (segno di frase in linguaggio naturale).

**Cosa `matching` NON tocca:** quante strategie girano, i pesi della fusione, la costante RRF, il
bonus accordo, il reranker. Il profilo viene passato solo alle chiamate `keyword` e `hybrid`; la
chiamata `vector` non lo riceve nemmeno, perché un embedding non ha idea di cosa sia un refuso.

Tradotto: se `tolerant` ti restituisce risultati che `strict` non dava, hai allargato la **recall
lessicale**. Se ti servono documenti che dicono la stessa cosa con parole diverse, quella è recall
**semantica**, e arriva dal vettoriale acceso, non da un profilo più largo.

---

## 5. Il multilingua

Il principio che regge tutto il refactoring recente:

- il **lessicale è per lingua**. `titolo` diventa un oggetto con sotto-campi:
  `titolo: { it: <analizzatore italiano>, en: <analizzatore inglese> }`. Serve perché lo stemming
  è diverso: in italiano `fatture` e `fattura` sono la stessa radice, in inglese no.
- il **semantico è cross-lingua**. Con un modello multilingua italiano e inglese finiscono nello
  stesso spazio numerico, quindi un solo confronto vettoriale su un array di vettori **senza
  lingua**. Elasticsearch restituisce il documento col punteggio del suo vettore migliore.
- la **lingua si filtra sul documento**, non sul vettore. Un campo `locales: [it, en]` dice in
  quali lingue quel contenuto esiste. Motivo: un contenuto bilingue che assomiglia di più al
  vettore inglese deve uscire comunque in una ricerca "solo italiano", perché in italiano esiste e
  in italiano verrà mostrato.

Ogni vettore salvato porta due timbri: da quale lingua è stato ricavato (`locale`) e quale modello
l'ha prodotto (`model_key`). Il secondo serve per sapere cosa è vecchio quando cambi modello:
`ai:embeddings:repair --stale` ripesca esattamente quelli.

Dettaglio che fa danni silenziosi: i modelli della famiglia e5 vogliono i prefissi `query:` e
`passage:`. Se indicizzi con uno e cerchi con l'altro non hai un errore, hai solo risultati
peggiori.

---

## 6. Le manopole che sembrano esistere ma non fanno niente

Verificate leggendo il codice. Utile saperle, perché girarle non produce effetti:

| Chiave | Stato reale |
|--------|-------------|
| `plan.retry_policy` | prodotta da entrambi i planner, validata da quello AI, consumata da nessuno |

---

## 7. Misurare invece di indovinare

Tutti i numeri visti sopra (0.35, 0.30, 60, 0.25, 0.15, 30, le soglie 100/75/65/60/55) sono stati
scelti a mano. L'unico modo per sapere se sono giusti sul tuo corpus è misurarli.

Esistono dataset di giudizi: una query scritta a mano e gli id dei documenti che dovrebbero uscire.

```json
{ "query": "come annullo un ordine", "expected_hit_ids": ["cms.contents:12"], "locale": "it" }
```

Due comandi ci girano sopra:

| Comando | Cosa misura |
|---------|-------------|
| `test:ai:evaluate-application-content` | qualità della lista finale, pipeline vera, senza LLM |
| `test:ai:evaluate-retrieval-strategies` | qualità **per strategia**: keyword, vector, hybrid, fused, reranked |

Il secondo esegue il motore due volte, con reranker spento e acceso, così vedi se il reranker sta
aiutando davvero o se lo stai pagando per niente.

Le metriche sono quelle standard dell'information retrieval:

- **precision@k**: dei primi k risultati, quanti erano giusti;
- **recall@k**: dei documenti giusti che esistono, quanti sono entrati nei primi k;
- **nDCG@k**: come sopra, ma pesando la posizione (essere primo vale più che essere quinto);
- **MRR**: a che posizione compare il primo risultato giusto.

Un baseline è committato nel repo e un test in CI lo confronta: se una modifica peggiora il
ranking, il test rompe.

**Nessun codice a runtime legge questi report.** Il ciclo è: misuro, leggo i numeri, cambio il
codice o la configurazione in un commit rivisto, rimisuro.

---

## 8. Livelli di adattività

Utile per non confondere le parole.

| Livello | Si adatta a | Dove stanno i parametri | Cosa serve |
|---------|-------------|-------------------------|------------|
| **L0**, il default | forma della query | costanti nel codice | niente |
| **L1**, dietro un interruttore | al tuo corpus, per classe di query | configurazione, committata | l'harness di valutazione |
| **L2** | comportamento degli utenti | store aggiornato a runtime | telemetria e volume |

Il sistema attuale è **già** adattivo: guarda la query e cambia comportamento (cifre presenti
spegne il vettoriale, query corta alza il peso del vettore, stopword rilassano la copertura). È
adattività a regole scritte da un umano. La domanda vera non è "vogliamo un sistema adattivo", è
"da dove vengono le regole".

L1 esiste: si cercano i parametri migliori girando l'harness di valutazione offline e si committa
il risultato. Riproducibile, revisionabile, protetto dal gate in CI. In pratica:

- l'interruttore è l'impostazione `search.adaptive_tuning` (gruppo `search`), spenta di default.
  Spenta, il piano è identico a L0, byte per byte;
- accesa, ogni query viene classificata riusando l'analisi che già si fa per il text matching:
  `identifier` (contiene un codice, un numero, una email, un UUID), `short_keyword` (una o due
  parole), `multi_term` (da tre a cinque), `natural_language` (una frase);
- per quella classe si applicano i parametri di `Modules/Core/config/search_tuning.php`: pesi,
  RRF, bonus accordo, top-K e miscela del reranker. Mai la scelta delle strategie: se il vettoriale
  gira resta una questione di capacità, non di tuning;
- ogni risposta dice cosa è successo in `meta.tuning` (versione del profilo e classe della query);
- i numeri li produce `php artisan test:ai:tune-retrieval`, che rigioca la fusione su una griglia di
  parametri senza rieseguire le query sul motore e stampa il blocco da incollare. Non scrive mai la
  configurazione: la committa un umano;
- per non scegliere valori che funzionano solo sui casi di prova, il tuner tiene da parte una quota
  dei casi (il 30% di default) e il vincitore non deve perdere, su quelli, contro il profilo attuale:
  altrimenti non c'è vincitore e si tiene il profilo com'è. Una classe di query riceve parametri
  propri solo se ha abbastanza casi (8 di default) e se batte il vincitore generale di un margine;
- un miglioramento piccolo come quello che farebbe un solo caso che cambia esito non è un
  risultato: il vincitore deve battere il profilo attuale di più di questo "margine di rumore"
  (di default un caso del campione, mai sotto 0,01), altrimenti non c'è vincitore. Lo stesso vale,
  sui casi della singola classe, per gli override di classe;
- un profilo con valori misurati deve citare il report da cui viene (chiave `report`, nella
  cartella `docs/evaluations/retrieval-tuning/` di Core): un test fallisce se il report manca, se non
  ha passato né la validazione sui casi tenuti da parte né il controllo del rumore, o se contiene
  valori diversi da quelli del profilo. Il report ha solo metriche e parametri, non il testo delle
  query né dei contenuti.

Il profilo spedito ripete le costanti L0, quindi accendere l'interruttore oggi cambia solo
`meta.tuning`, e non deve citare nessun report. Il giro di tuning vero va fatto a mano, con
Elasticsearch e gli embedding attivi.

L2 richiede di registrare cosa fanno gli utenti, e oggi in Laraplate non viene registrato nulla:
nessuna tabella di query, nessun click. Senza quel dato un sistema che "impara" imparerebbe dal
nulla.

---

## 9. Glossario minimo

| Termine | In parole semplici |
|---------|--------------------|
| **BM25** | il punteggio classico della ricerca per parole: premia le parole rare e i documenti corti |
| **Embedding** | il testo trasformato in una lista di numeri che ne rappresenta il significato |
| **kNN** | trova i k vettori più vicini a quello della query |
| **Hybrid** | una sola query al motore che usa insieme parole e vettore |
| **RRF** | fusione basata sulla posizione, non sul punteggio: `1 / (k + posizione)` |
| **Reranker** | un secondo giudice che guarda il testo dei primi risultati e li riordina |
| **Analyzer** | la regola con cui il motore spezza e normalizza il testo, diversa per lingua |
| **Recall** | quanta della roba giusta sei riuscito a trovare |
| **Precision** | quanta della roba che hai trovato era giusta |
