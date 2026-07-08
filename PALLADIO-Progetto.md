# PALLADIO
## Sistema WordPress per la vendita frazionata di immobili
### Documento di progetto — Visione completa + Specifica MVP

**Versione:** 0.9 (draft di progetto)
**Data:** luglio 2026
**Caso pilota:** Palazzo Sambiasi, Lecce
**Stack AI:** OpenAI API

---

## 1. Visione di prodotto

Palladio è un plugin WordPress che trasforma un sito in un **sistema di regia completo per la vendita di unità immobiliari appartenenti a uno o più edifici**: presentazione, generazione contenuti multilingua via AI, agent conversazionale di qualificazione, gestione lead, monitoraggio e distribuzione verso i portali.

**Il problema.** I plugin real estate esistenti (Estatik, Houzez, WP Residence, Realtyna) sono directory di annunci pensate per agenzie con portafogli eterogenei. Nessuno è progettato per il caso "un asset, molte unità, una regia": la vendita frazionata di un palazzo storico, di un borgo, di una palazzina di nuova costruzione, di un residence. In questi casi il venditore non ha bisogno di un motore di ricerca annunci, ma di:

1. raccontare **l'edificio come brand** e le unità come sue declinazioni;
2. gestire **configurazioni variabili** (accorpamenti, frazionamenti, bundle);
3. parlare a **compratori internazionali** in più lingue senza moltiplicare il lavoro editoriale;
4. **qualificare i lead 24/7** su fusi orari diversi;
5. avere una **cabina di regia**: quali unità tirano, da quali canali arrivano i contatti, a che punto è ogni trattativa;
6. **distribuire** le schede sui portali senza doppio inserimento dati.

**La tesi di prodotto.** Ogni operazione di vendita frazionata è una campagna con un inizio e una fine. Palladio è il software di quella campagna: si installa, si configura per il progetto, accompagna la vendita, e a fine operazione il know-how (template, prompt, feed) resta riutilizzabile per l'operazione successiva.

**Posizionamento a lungo termine.** Nato come plugin per casi propri, ha una traiettoria naturale verso prodotto commerciale (freemium su wp.org + Pro a licenza) o verso servizio gestito per sviluppatori/promotori immobiliari.

---

## 2. Attori e casi d'uso

| Attore | Cosa fa con Palladio |
|---|---|
| **Regista della vendita** (proprietario/marketer) | Configura il progetto, genera e revisiona contenuti, monitora dashboard, gestisce pipeline lead |
| **Agenzia partner** | Riceve i lead qualificati (email/webhook/CRM), aggiorna gli stati delle trattative |
| **Compratore** | Naviga edificio e unità, usa planimetrie interattive, dialoga con l'agent nella propria lingua, scarica dossier, richiede visita |
| **Traduttore/revisore** (opzionale) | Revisiona le versioni linguistiche generate dall'AI prima della pubblicazione |
| **Portali** | Ricevono feed strutturati delle unità disponibili |

**Casi d'uso coperti dal modello dati:**
- Palazzo storico frazionato (caso pilota)
- Nuova costruzione / cantiere con listino unità
- Borgo o complesso multi-edificio (più edifici in un progetto)
- Residence turistico con vendita a investitori
- Vendita mista residenziale + commerciale

---

## 3. Modello dati

Gerarchia a tre livelli più l'entità trasversale Scenario:

```
Progetto (opzionale, per operazioni multi-edificio)
 └── Edificio            CPT: pll_edificio
      └── Unità          CPT: pll_unita  (post_parent → edificio)

Scenario                 CPT: pll_scenario (bundle/split di unità)
Lead                     Tabella custom: {prefix}palladio_leads
Conversazione            Tabella custom: {prefix}palladio_chats
Evento analytics         Tabella custom: {prefix}palladio_events
Knowledge chunk          Tabella custom: {prefix}palladio_kb (RAG)
```

### 3.1 Edificio (`pll_edificio`)
Il contenitore-brand. Campi principali:
- Identità: nome, claim, storia/descrizione lunga, indirizzo, geo (lat/lng)
- Dati: anno costruzione, mq totali, numero piani, numero unità totali/in vendita
- Vincoli e note legali (es. vincolo D.Lgs 42/2004, prelazione statale) — campo strutturato che si propaga alle unità e alla KB dell'agent
- Documenti condominiali: regolamento, tabelle millesimali, costi annui stimati
- Media: gallery, video hero, virtual tour edificio, **planimetrie di piano (SVG)** con mappatura zone→unità
- Contatti di vendita (agenzia, telefono, email) — usati da agent e form

### 3.2 Unità (`pll_unita`)
Il prodotto in vendita. Campi:
- Relazione: edificio (post_parent), piano, numero/codice unità
- Commerciali: prezzo, prezzo trattabile (bool), millesimi, spese condominiali stimate, classe energetica, stato di consegna (libera / locata fino a…, con data)
- Fisici: mq commerciali, mq coperti, vani, camere, bagni, esposizione, livelli, pertinenze (terrazza mq, giardino mq, deposito…)
- Caratteristiche qualitative (repeater): "volte a stella", "vista Duomo", "camino monumentale"… — usate da Composer per la generazione testi
- Destinazione d'uso attuale + **cambi d'uso possibili** (con flag "verificato da tecnico" e nota)
- Media: gallery, planimetria unità, virtual tour URL, video
- **Stato di vendita** (tassonomia `pll_stato`): `disponibile | riservata | in_trattativa | venduta | non_in_vendita`
  → `non_in_vendita` mantiene l'unità nel sistema e nelle planimetrie (colorata come "già venduta/privata") senza scheda commerciale: esattamente il caso delle unità 9-13 di Sambiasi.
- Tassonomie: `pll_tipologia` (appartamento, locale commerciale, deposito, giardino, box…), `pll_piano`, `pll_stato`

### 3.3 Scenario (`pll_scenario`)
La feature distintiva: configurazioni alternative di vendita.
- **Bundle**: elenco di unità accorpate + prezzo pacchetto + descrizione dedicata (es. "Piano terra completo: 3 appartamenti + deposito, €490.000")
- **Split**: unità madre + definizione delle sotto-unità virtuali (es. "Piano Nobile — Ala destra ~€425.000") con planimetria dedicata
- Regole di coerenza: se un'unità di un bundle passa a `venduta`, lo scenario passa automaticamente a `non_disponibile` con notifica al regista
- Gli scenari compaiono nel frontend come "Configurazioni possibili" e sono oggetti conversazionali per l'agent ("posso comprare tutto il piano terra?")

### 3.4 Lead
Tabella custom (non CPT: volumi, query, privacy):
```
id, created_at, source (utm_source/medium/campaign), lang,
nome, email, telefono, paese,
unita_ids (json), scenario_id,
budget_range, uso_previsto (residenza|investimento|ricettivo|commerciale),
timeline, note, consenso_gdpr (bool, timestamp, testo versione),
stato (nuovo|qualificato|inviato_agenzia|visita|trattativa|chiuso_vinto|chiuso_perso),
chat_id (fk conversazione), score (0-100 calcolato)
```

### 3.5 Multilingua — modello
Vedi §6. In sintesi: le traduzioni dei campi strutturati e dei contenuti vivono in un meta dedicato per lingua (`_pll_i18n_{lang}` → json dei campi tradotti), generate da OpenAI e revisionabili in un editor affiancato. Compatibilità opzionale con Polylang/WPML tramite adapter.

---

## 4. Architettura tecnica

```
palladio/
├── palladio.php                  ← bootstrap, costanti, autoloader
├── uninstall.php
├── includes/
│   ├── class-palladio.php        ← loader/orchestratore, registra hook
│   ├── class-activator.php       ← creazione tabelle, ruoli, defaults
│   ├── core/                     ← MODULO CORE
│   │   ├── class-cpt.php         ← CPT + tassonomie
│   │   ├── class-meta.php        ← registrazione campi (register_post_meta, show_in_rest)
│   │   └── class-scenario.php    ← logica bundle/split e regole di coerenza
│   ├── i18n/                     ← MODULO LINGUE
│   │   ├── class-languages.php   ← lingue attive, routing ?lang= / /en/, hreflang
│   │   └── class-translator.php  ← pipeline di traduzione via Composer
│   ├── ai/                       ← MODULO AI (OpenAI)
│   │   ├── class-openai.php      ← client HTTP (chat, embeddings), retry, costi
│   │   ├── class-composer.php    ← generazione schede/traduzioni da dati strutturati
│   │   ├── class-agent.php       ← agent conversazionale (RAG + qualificazione)
│   │   └── class-kb.php          ← knowledge base: chunking, embeddings, ricerca
│   ├── frontend/                 ← MODULO PRESENTER
│   │   ├── class-shortcodes.php  ← shortcode + blocchi Gutenberg
│   │   ├── class-templates.php   ← template loader (override da tema)
│   │   └── class-schema.php      ← JSON-LD RealEstateListing/Offer/Place
│   ├── admin/                    ← MODULO REGIA
│   │   ├── class-admin.php       ← menu, settings (API key, lingue, GDPR)
│   │   ├── class-dashboard.php   ← KPI, funnel, heat ranking unità
│   │   └── class-leads.php       ← pipeline lead (list table + stati)
│   ├── api/
│   │   └── class-rest.php        ← REST: /agent/chat, /lead, /track
│   └── feeds/                    ← MODULO DISTRIBUZIONE
│       ├── class-feed-manager.php
│       └── adapters/             ← immobiliare-it.php, idealista.php, gateaway.php
├── assets/
│   ├── js/agent-widget.js        ← widget chat embeddabile
│   ├── js/floorplan.js           ← SVG interattivo (stati, hover, click→scheda)
│   └── css/…
├── templates/                    ← edificio.php, unita.php, scenario.php (override-abili)
└── languages/                    ← .pot per l'interfaccia del plugin stesso
```

**Principi:**
- **PHP 8.1+, WP 6.4+**, nessuna dipendenza da page builder; frontend via shortcode/blocchi + template override-abili dal tema (`{tema}/palladio/unita.php`)
- Tutti i meta con `show_in_rest` → il plugin è headless-ready
- Le chiavi API OpenAI in `wp_options` cifrate (libsodium se disponibile) e mai esposte al client; tutte le chiamate AI passano dal server
- Ogni modulo attivabile/disattivabile (constant/filtro) → installazioni leggere per casi semplici
- Event bus interno (`do_action('palladio/lead_created')`, `palladio/unit_status_changed`, …) → estensibilità e integrazioni

---

## 5. I moduli nel dettaglio

### 5.1 Core
CPT, tassonomie, meta, scenari, stati. Include la logica di **propagazione**: dati dell'edificio (vincoli, contatti, documenti) ereditati dalle unità dove non sovrascritti.

### 5.2 Presenter (frontend)
- **Pagina edificio**: hero, storia, griglia unità filtrabile per piano/stato/tipologia, planimetrie di piano interattive, scenari, blocco "come funziona l'acquisto" (millesimi, vincoli, prelazione — trasparenza che qualifica)
- **Pagina unità**: gallery, dati strutturati in tabella, caratteristiche, virtual tour embed, planimetria, CTA (dossier PDF gated + richiesta visita + agent), unità correlate dello stesso edificio
- **Planimetria SVG interattiva**: l'admin carica l'SVG del piano e mappa gli `id` dei path alle unità; il JS colora per stato (disponibile/riservata/venduta/privata), tooltip con prezzo/mq, click → scheda. Fallback immagine statica.
- **JSON-LD** automatico: `RealEstateListing`, `Offer` (prezzo, valuta, disponibilità), `Place`/`geo`, `FloorPlan`. Meta hreflang per lingua. Struttura pensata anche per GEO/AEO: blocco FAQ per unità e per edificio renderizzato come contenuto reale + `FAQPage`.

### 5.3 Composer (contenuti AI)
Genera contenuti **a partire dai dati strutturati**, mai il contrario:
1. Il regista compila i campi dell'unità (mq, prezzo, caratteristiche, punti di forza in bullet grezzi)
2. Composer costruisce il prompt (template per tipologia + tone of voice del progetto, configurabile a livello di edificio) e genera: titolo, abstract, descrizione lunga, meta description, FAQ
3. Output in **stato bozza** → editor di revisione affiancato (originale | generato) → approvazione → pubblicazione
4. Rigenerazione selettiva per singolo campo

Modelli: `gpt-4.1` per generazioni lunghe, `gpt-4.1-mini` per meta/varianti brevi (configurabile). Log token e costo stimato per operazione.

### 5.4 Lingue (i18n dei contenuti)
Doppio binario:

**A. Modalità nativa (default, zero dipendenze).**
- Lingue attive configurabili (es. IT sorgente + EN, DE, FR)
- Per ogni unità/edificio/scenario: pannello "Lingue" con stato per lingua (`assente | generata | revisionata | pubblicata`)
- Traduzione via Composer: prompt di traduzione **contestuale** (non letterale: adatta unità di misura, riferimenti culturali, registro; il glossario di progetto blocca i termini da non tradurre — "volte a stella" → "star vaults" definito una volta sola)
- Storage: meta `_pll_i18n_{lang}` (json completo dei campi testuali tradotti); il frontend risolve la lingua da URL (`/en/…` via rewrite o parametro) e fa fallback alla sorgente per i campi mancanti
- hreflang + sitemap per lingua generati dal plugin
- Traduzione batch: "genera EN per tutte le unità disponibili" → coda (Action Scheduler) → revisione

**B. Modalità adapter (se il sito usa già Polylang/WPML).**
Palladio rileva il plugin multilingua, crea i post tradotti collegati e li popola via Composer. Stessa UX di generazione, storage delegato.

L'agent (5.5) è indipendente da tutto questo: risponde nella lingua dell'utente qualunque essa sia.

### 5.5 Agent (OpenAI)
Concierge di vendita + qualificatore lead. Architettura RAG + function calling:

- **Knowledge base per edificio**: alla pubblicazione/modifica di unità, scenari, FAQ e documenti (regolamento condominiale, guida all'acquisto per stranieri…), il modulo KB fa chunking, calcola embeddings (`text-embedding-3-small`) e li salva nella tabella `palladio_kb` (colonna vector serializzata; ricerca per cosine similarity in PHP — a questi volumi, poche centinaia di chunk, non serve un vector DB)
- **Runtime**: widget chat → REST `/palladio/v1/agent/chat` → retrieval top-k chunk → Chat Completions (`gpt-4.1-mini` default, `gpt-4.1` opzione) con system prompt parametrico del progetto
- **Function calling / tools**:
  - `get_unit_details(unit_id)` — dati sempre freschi dal DB (prezzi e stati NON dalla KB: mai rischiare prezzi obsoleti)
  - `list_available_units(filters)` — "cosa avete sotto i 150.000 con terrazza?"
  - `save_lead(fields)` — quando l'utente lascia contatti; consenso GDPR esplicito in chat
  - `request_visit(unit_id, contact)` — genera lead con stato `visita`
  - `handoff_human(reason)` — escalation: email/webhook immediato all'agenzia con trascrizione
- **Qualificazione conversazionale**: il system prompt istruisce l'agent a raccogliere naturalmente (non a interrogatorio) budget, uso previsto, tempistiche, paese; a fine sessione un'estrazione strutturata (json mode) aggiorna il lead e calcola lo score
- **Guardrail**: risponde solo su ciò che è in KB/DB; su temi legali/fiscali dà l'informazione generale presente in KB e propone il contatto umano; disclaimer configurabile; nessuna promessa su cambi d'uso non flaggati "verificato"
- **Multilingua nativo**: rileva e segue la lingua dell'utente
- Log completo conversazioni (tabella `palladio_chats`) consultabile dalla regia: le domande reali dei prospect sono la migliore fonte per migliorare schede e FAQ

### 5.6 Regia (dashboard e lead)
- **Dashboard**: lead per periodo/fonte/lingua; funnel (visite → dossier → chat → lead → visita fisica → trattativa); **heat ranking unità** (interesse relativo: pageview + dossier + menzioni in chat) → guida la riallocazione del budget adv; costo/lead per canale (se collegato a spesa manuale o API ads in v2)
- **Pipeline lead**: list table con stati kanban-like, note, storico, trascrizione chat collegata, azioni (invia all'agenzia, segna visita, chiudi)
- **Notifiche**: email/webhook su nuovo lead qualificato, su richiesta visita, su handoff agent
- **Tracking**: endpoint `/track` per eventi custom (apertura tour, download dossier) + integrazione GA4 (eventi datalayer) senza dipendere da GA per il funzionamento interno

### 5.7 Dossier PDF
Generazione server-side del dossier per unità/scenario nella lingua richiesta: template HTML/CSS → PDF (Dompdf in MVP per zero dipendenze binarie; driver mPDF/WeasyPrint via filtro per output tipografico superiore, riusando la pipeline già collaudata su BookDesigner). Download gated: form → lead → email con link. Il PDF include QR verso la scheda e il tour.

### 5.8 Feeds (distribuzione portali)
Feed manager con adapter per formato: export XML/CSV secondo specifiche Immobiliare.it, Idealista, Gate-away/Kyero (real estate feed standard). Ogni adapter mappa i campi Palladio → campi portale; cron di rigenerazione; URL feed protetto da token. I lead di ritorno dai portali si riconciliano per fonte in dashboard.

---

## 6. Sicurezza, privacy, conformità

- **GDPR**: consenso esplicito e versionato su ogni form e in chat prima del salvataggio dati; export/cancellazione lead integrati con gli strumenti privacy di WP; data retention configurabile (auto-anonimizzazione lead chiusi dopo N mesi); registro dei trattamenti documentato nel plugin
- **AI transparency**: il widget dichiara che si tratta di assistente AI (obbligo AI Act per i sistemi conversazionali); trascrizioni conservate con la stessa policy dei lead
- Nonce + rate limiting su tutti gli endpoint REST pubblici (agent: limite messaggi/sessione e sessioni/IP per controllo costi e abusi)
- Capability dedicate: `manage_palladio` (regia), `edit_palladio_content` (editor/traduttore)
- Sanitizzazione SVG in upload (whitelist elementi) — vettore XSS noto

---

## 7. Roadmap

### FASE 0 — MVP (4-6 settimane equivalenti) → in produzione su Sambiasi
Obiettivo: vendere con il sistema, non dimostrare il sistema.
- ✅ Core: CPT edificio/unità + tassonomie + meta + stati (inclusi `non_in_vendita`)
- ✅ Presenter: template edificio + unità, griglia filtrabile, JSON-LD, form lead classico
- ✅ Lingue modalità nativa: IT + EN, generazione via OpenAI, editor revisione, hreflang
- ✅ Composer: generazione scheda da dati strutturati (IT) + traduzione EN
- ✅ Agent v1: RAG su schede + FAQ, tools `get_unit_details` / `list_available_units` / `save_lead`, widget frontend, log conversazioni
- ✅ Regia v1: lista lead con stati + notifiche email + contatore eventi base
- ✅ Settings: API key, lingue, testi GDPR, contatti progetto
- Fuori scope MVP ma con hook già pronti: scenari (si gestiscono come pagine manuali), SVG interattivo (immagine mappata statica), PDF (dossier caricati a mano), feed

### FASE 1 — v1.0 (successive 6-8 settimane)
- Scenari bundle/split con regole di coerenza
- Planimetrie SVG interattive con editor di mappatura
- Dossier PDF dinamici multilingua
- Dashboard completa (funnel, heat ranking, fonti)
- Feed Immobiliare.it + Gate-away
- Lingue DE/FR + glossario di progetto
- Agent: `request_visit`, `handoff_human`, estrazione score

### FASE 2 — v2.0 (prodotto)
- Multi-progetto/multi-edificio con dashboard aggregata
- Adapter Polylang/WPML
- Integrazioni CRM (HubSpot, Pipedrive) e calendari visite
- Import spesa ads (API Google/Meta) → CPL reale per canale in dashboard
- White label, licensing, onboarding wizard → distribuzione commerciale
- Proposta d'acquisto digitale (form vincolante + firma elettronica via provider)

---

## 8. Stima costi operativi AI (ordine di grandezza, caso Sambiasi)

| Voce | Volume mensile stimato | Costo |
|---|---|---|
| Generazione contenuti (7 unità × 4 lingue, una tantum + revisioni) | ~200k token | < €5 una tantum |
| Agent (500 conversazioni × ~8 turni, gpt-4.1-mini + RAG) | ~10M token | €10-25/mese |
| Embeddings KB (rigenerazioni) | trascurabile | < €1/mese |

Il costo AI è irrilevante rispetto al budget adv: nessun vincolo di progetto.

---

## 9. Backlog MVP — user stories operative

1. **Come regista** creo un Edificio con storia, vincoli, documenti condominiali e planimetrie di piano.
2. **Come regista** creo le Unità collegate all'edificio con tutti i dati commerciali e fisici; le unità non in vendita restano visibili come "privata/venduta".
3. **Come regista** compilo i campi e clicco "Genera scheda": Composer produce titolo, descrizione, FAQ in italiano; revisiono e pubblico.
4. **Come regista** clicco "Genera EN": ottengo la versione inglese in bozza, la revisiono, la pubblico; il sito serve /en/ con hreflang.
5. **Come compratore** navigo l'edificio, filtro le unità per piano e prezzo, apro una scheda con gallery, dati e virtual tour.
6. **Come compratore** chatto con l'agent in inglese alle 3 di notte: mi dice prezzi e disponibilità aggiornati, risponde sul vincolo storico, mi chiede budget e tempistiche, salva i miei contatti con consenso.
7. **Come regista** ricevo l'email "nuovo lead qualificato" con score e trascrizione; cambio lo stato in "inviato all'agenzia".
8. **Come regista** vedo in dashboard quante visite, quanti lead e da quali fonti, per unità.

---

## 10. Naming e brand

"Palladio" (working title) è forte: architetto, ordine, misura — e memorizzabile nel mercato internazionale. Verificare prima della distribuzione pubblica: disponibilità slug su wp.org, marchi registrati in classe software/real estate, dominio (palladio.***). Alternative di riserva se il nome fosse bloccato: *Frazionale*, *Palinsesto*, *Piano Nobile* (quest'ultimo evocativo ma meno generico).

---

*Documento di progetto redatto per lo sviluppo del plugin. Il caso pilota Palazzo Sambiasi valida: unità escluse dalla vendita, millesimi, vincolo ministeriale, cambio d'uso condizionato, scenari di accorpamento/frazionamento, target internazionale multilingua.*
