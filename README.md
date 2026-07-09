# Palladio

Sistema WordPress per la vendita frazionata di immobili: **un edificio, molte unità, una campagna**.

Palladio trasforma un sito WordPress in un sistema di regia completo per la vendita di unità immobiliari appartenenti a uno o più edifici. La visione di prodotto completa, il modello dati e la roadmap sono in [`PALLADIO-Progetto.md`](PALLADIO-Progetto.md).

## Stato

**Versione 0.9.0 — perimetro completo + i18n a pagine clone + direzione visiva editoriale.** Sono implementati tutti i moduli previsti dall'architettura del documento di progetto (§4): Core, Presenter (integrato con [PoeTheme](https://github.com/cosemurciano/PoeTheme)), Regia (lead + dashboard), Lingue (i18n nativo **a pagine clone per lingua**), AI/Composer, Agent conversazionale e Feeds portali. Le schede di edificio e unità adottano una **direzione visiva editoriale "Sambiasi"** (palette calda, serif da display, hero full-bleed, barra dati sticky, narrazione asimmetrica, scheda tecnica tipografica, unità sorelle, dossier). Le voci residue sono affinamenti di Fase 1 (scenari bundle/split completi, planimetrie SVG interattive, dossier PDF, `request_visit`/`handoff_human`, scoring lead, adapter Polylang/WPML).

### Direzione visiva editoriale

I template `single-pll_unita.php` e `single-pll_edificio.php` implementano lo stile editoriale (`assets/css/palladio-editorial.css`, font Cormorant Garamond / Marcellus / Hanken Grotesk): hero fotografico full-bleed, **barra sticky** con prezzo e dati chiave + CTA, narrazione, **scheda tecnica** tipografica, virtual tour, posizione nell'edificio, **unità sorelle** e **dossier** (form lead). Disattivabile via filtro `palladio/editorial/enabled`. Il tema [PoeTheme](https://github.com/cosemurciano/PoeTheme) offre una **palette + abbinamento font "Sambiasi"** coordinati per header/footer.

### Landing dell'edificio e homepage

- **Metabox "Dati principali"** (`includes/admin/class-fields.php`): interfaccia dedicata e **tipizzata** per i campi commerciali/fisici del modello (§3), finora registrati ma senza UI. Edificio: claim, sottotitolo/secolo, indirizzo, anno, superficie totale, piani, unità in vendita, vincoli, contatti, geo. Unità: **codice/etichetta** (es. “app. 1 + 2 + 3”), prezzo, superfici, vani/camere/bagni, classe energetica, millesimi, spese, terrazza/giardino, consegna, uso, tour/video.
- **Edificio come homepage**: dal metabox dell'edificio, "Usa questo edificio come homepage del sito" → la landing dell'edificio è servita alla radice (`option palladio_home_building`, override in `template_include`), **distinta** dalle schede delle singole unità.
- **Landing arricchita** (`single-pll_edificio.php`): occhiello con indirizzo · sottotitolo, valori chiave dell'edificio, **fascia prezzi** ("N residenze · €min – €max") calcolata dalle unità, ed **elenco delle unità come in grafica** (immagine, badge stato, occhiello piano · m² · codice, descrizione breve, prezzo). Helper `palladio_units_price_range()` e `palladio_unit_eyebrow()`.

### Contenuti strutturati (non testo libero)

Il metabox **"Contenuti della scheda"** (`includes/admin/class-content.php`) popola i campi del template con **campi dedicati e repeater**, non un testo libero, con **set differenziati per Edificio e Unità**:

- **Unità**: occhiello, lead, URL walkthrough, **capitoli**, **narrazione asimmetrica**, **scheda tecnica**, **planimetria**, **galleria**, **posizione**.
- **Edificio (landing)**: occhiello, lead, **Manifesto** (affermazioni con enfasi), **Timeline / scroll-telling** (occhiello, anno, titolo, testo, immagine), **Ambient loop** (immagine + didascalia), **sezione unità** (occhiello, titolo, toggle filtri), **galleria** (con link "Tutta la galleria" + numero foto).

Salvati in `_pll_editorial` e letti da `palladio_editorial()`; immagini scelte con il media picker di WordPress. La landing dell'edificio (`single-pll_edificio.php`) rende, conforme alle immagini di riferimento: hero, **manifesto** (reveal allo scroll), **timeline** con anni, **ambient loop** a piena larghezza, **unità "Scegli le tue stanze"** con **filtri client-side** (Tutte / Piano / Prezzo / Con spazio esterno) e card con scatto/badge/occhiello/descrizione/prezzo, e **galleria asimmetrica** (masonry). Reveal e filtri sono progressive enhancement in `assets/js/palladio.js`.

### Cosa fa già

- **Bootstrap del plugin** (`palladio.php`) con costanti, autoloader e hook di attivazione/disattivazione.
- **CPT del modello dati** (`includes/core/class-cpt.php`):
  - `pll_edificio` — il contenitore-brand.
  - `pll_unita` — l'unità in vendita (gerarchica, `post_parent` → edificio).
  - `pll_scenario` — bundle/split di unità.
- **Tassonomie** delle unità: `pll_tipologia`, `pll_piano`, `pll_stato` (con i 5 termini di default: `disponibile`, `riservata`, `in_trattativa`, `venduta`, `non_in_vendita`, popolati all'attivazione).
- **Meta strutturati** (`includes/core/class-meta.php`) per edificio e unità, tutti `show_in_rest` (headless-ready) con sanitizzazione e `auth_callback`.
- **Scenari** (`includes/core/class-scenario.php`): meta bundle/split + hook della regola di coerenza (`palladio/unit_status_changed`).
- **Attivazione** (`includes/class-activator.php`): registra i CPT prima del flush delle rewrite rules, popola i termini di stato, assegna le capability `manage_palladio` / `edit_palladio_content` all'administrator.
- **Presenter / frontend** (`includes/frontend/`):
  - `class-templates.php` — instrada i template dei CPT lasciando la **precedenza al tema** (override via `{tema}/palladio/single-pll_unita.php`).
  - `class-assets.php` — carica CSS/JS solo sulle viste del plugin, versionati con `filemtime()`.
  - `class-shortcodes.php` — `[palladio_edifici]` per inserire la griglia degli edifici in qualsiasi pagina.
  - `template-functions.php` — helper di presentazione (prezzo, badge di stato, card unità, specifiche).
  - Template: `templates/single-pll_edificio.php` (hero + fatti chiave + griglia unità filtrabile), `single-pll_unita.php` (prezzo, stato, tabella caratteristiche, CTA, unità correlate), `archive-pll_edificio.php`.
- **Regia / lead** (`includes/leads/`, `includes/admin/`):
  - `leads/class-store.php` — data layer sulla tabella custom `{prefix}palladio_leads` (§3.4): creazione/upgrade schema, inserimento sanitizzato, cambio stato, query e conteggi aggregati.
  - `leads/class-form.php` — form di cattura lead (shortcode `[palladio_lead_form]` + iniezione automatica nel pannello contatti dell'unità), con nonce, honeypot, consenso GDPR versionato e notifica email alla regia/agenzia.
  - `admin/class-regia.php` — menu **Palladio → Regia** (dashboard KPI: totali, pipeline per stato, fonti, ultimi lead) e **Palladio → Lead** (pipeline).
  - `admin/class-leads-list-table.php` — `WP_List_Table` dei lead con filtri per stato, ricerca, paginazione e transizioni di stato (row actions con nonce).
  - Stati lead (§3.4): `nuovo`, `qualificato`, `inviato_agenzia`, `visita`, `trattativa`, `chiuso_vinto`, `chiuso_perso`.
  - Event bus: `palladio/lead_created`, `palladio/lead_status_changed`.
- **Lingue / i18n contenuti** (`includes/i18n/`, `includes/admin/class-translations.php`) — modalità nativa a zero dipendenze, **modello a pagine clone** (§5.4.A):
  - Ogni lingua è una **pagina CPT dedicata** collegata all'originale da un **gruppo di traduzione** (`_pll_tgroup`) e con la propria lingua (`_pll_lang`) — non campi dentro il post originale.
  - `i18n/class-translator.php` — data layer dei gruppi: lingua per post, `siblings()`, **`clone_post()`** (clona titolo/contenuto/meta/tassonomie/immagine in una nuova bozza collegata, con genitore risolto nella stessa lingua) e **`sync_shared()`** (prezzi, stato, misure e immagine restano sincronizzati tra i cloni; i testi sono per-lingua).
  - `i18n/class-languages.php` — configurazione sorgente + lingue attive (IT/EN/DE/FR), pagina **Palladio → Lingue**, lingua corrente derivata dal **post visualizzato**, filtro archivi per lingua (`pre_get_posts`), **hreflang** verso i post collegati e shortcode `[palladio_lang_switcher]` (link alle versioni).
  - `admin/class-translations.php` — riquadro **Lingue** sui CPT: mostra la lingua della pagina e, per ogni lingua attiva, **Modifica** (se la versione esiste) o **Crea versione** (clona in una nuova pagina editabile).
  - La traduzione AI (Composer) **crea e popola la pagina clone** come bozza da rivedere.
- **AI / Composer** (`includes/ai/`, `includes/admin/class-ai.php`) — richiede una chiave OpenAI (§5.3, §6):
  - `ai/class-crypto.php` — cifratura della chiave API con **libsodium** (chiave derivata dai salt WP, mai memorizzata); fallback offuscato se libsodium assente.
  - `ai/class-settings.php` — pagina **Palladio → AI**: chiave API, modelli configurabili, abilitazione e **riepilogo uso/costi stimati**. La chiave può essere definita in **`wp-config.php`** con `define( 'PALLADIO_OPENAI_API_KEY', 'sk-...' );` (opzione più sicura, ha priorità e rende il campo di sola lettura); in alternativa è salvata cifrata nel database.
  - `ai/class-openai.php` — client HTTP server-side (Chat Completions + Embeddings) con **retry** su errori transitori e log token/costo. La chiave non lascia mai il server.
  - `ai/class-composer.php` — genera la scheda (titolo, abstract, descrizione, meta description, FAQ) **dai dati strutturati** come bozza rivedibile (`_pll_ai_draft`), la applica su richiesta, e **traduce** titolo/riassunto/contenuto salvando nel data layer i18n con stato `generata`.
  - `admin/class-ai.php` — metabox con pulsanti *Genera scheda / Applica bozza / Genera traduzione*, **Costruisci da Storage + Media** e **Carica documenti su Storage**, via **AJAX** (nonce + capability `edit_post`); nessuna chiamata AI dal browser.
  - **OpenAI Storage + File Search + media** (`ai/class-openai.php`, `ai/class-composer.php`): il client supporta **Responses API con File Search** (`vector_store_ids`), l'**upload file** e i **vector store**. `Composer::build_from_sources()` interroga i **documenti del progetto su OpenAI Storage** (File Search) e sceglie le immagini dai **media del sito** per **popolare i campi strutturati** (`_pll_editorial` + meta) con i contenuti corretti; `Composer::upload_documents()` carica i documenti allegati (PDF/testo) sul **vector store** configurato in **Palladio → AI**.
- **Agent conversazionale** (`includes/agent/`) — concierge di vendita + qualificatore lead (§5.5), richiede l'AI configurata:
  - `agent/class-kb.php` — Knowledge Base RAG: alla pubblicazione dei CPT indicizza i contenuti in chunk con **embeddings** nella tabella `palladio_kb`; ricerca per **cosine similarity** in PHP.
  - `agent/class-chats.php` — log conversazioni nella tabella `palladio_chats` (consultabile dalla regia).
  - `agent/class-tools.php` — **function calling**: `get_unit_details` e `list_available_units` (prezzi/stati **sempre freschi dal DB**, mai dalla KB) e `save_lead` (con consenso, aggancio a Regia).
  - `agent/class-rest.php` — endpoint `POST /palladio/v1/agent/chat`: retrieval top-k + chat completion con system prompt parametrico, **loop di tool**, **rate limiting** per IP e **guardrail** (risponde solo dal contesto/tool, disclaimer, consenso prima di salvare dati).
  - `agent/class-widget.php` + `assets/js/agent-widget.js` — widget chat embeddabile che **dichiara la natura AI** (trasparenza, AI Act) e parla solo con l'endpoint REST; nessuna chiave o chiamata AI lato browser. Stili palette-aware in `assets/css/palladio-agent.css`.
  - Impostazioni Agent (abilitazione, top-k, rate limit, disclaimer, system prompt) nella pagina **Palladio → AI**.
- **Feeds portali** (`includes/feeds/`) — distribuzione verso i portali (§5.8):
  - `feeds/class-manager.php` — endpoint pubblico del feed **protetto da token** (`hash_equals`), **cache** con TTL filtrabile e **cron orario** di rigenerazione; pagina **Palladio → Feeds** con gli URL e le azioni (rigenera / reset token).
  - `feeds/class-adapter.php` — base astratta che mappa i campi Palladio → campi portale (query delle unità in vendita, immagini, geo dall'edificio).
  - `feeds/class-kyero.php` — export **Kyero v3 (XML)**, formato aperto accettato da Gate-away.com, Kyero e Idealista international (costruito con DOMDocument).
  - `feeds/class-csv.php` — export **CSV generico** portabile.
  - I tracciati proprietari (es. Immobiliare.it) si aggiungono estendendo `Palladio_Feeds_Adapter` e registrandoli via il filtro `palladio/feeds/adapters`.

### Integrazione con PoeTheme

Il Presenter è progettato per essere **perfettamente integrabile con PoeTheme** (ma funziona su qualsiasi tema):

- I template chiamano `get_header()` / `get_footer()`, quindi ereditano automaticamente **header, subheader (breadcrumb + titolo) e footer** del tema. Il contenuto è renderizzato direttamente nel `<main>`, che su PoeTheme è già il container con larghezza e padding — nessun doppio wrapper.
- Lo stile (`assets/css/palladio.css`) è **autosufficiente per il layout** (nessuna dipendenza dalle utility Tailwind purgate del tema) ma consuma le **variabili di palette** di PoeTheme (`--poetheme-content-*`, `--poetheme-cta-*`, `--poetheme-heading-*`) con fallback neutri: card, prezzi, tabelle e pulsanti adottano automaticamente i colori della palette attiva in Style Studio.
- Feature-detection via `palladio_is_poetheme()` / `palladio_header_owns_title()`: nessun errore né titolo duplicato quando il tema gestisce già il titolo di pagina.

### Architettura

```
palladio/
├── palladio.php                     ← bootstrap, costanti, hook
├── uninstall.php                    ← cleanup opzioni/capability (non i contenuti)
├── assets/
│   ├── css/palladio.css             ← stili frontend (palette-aware)
│   ├── css/palladio-admin.css       ← stili dashboard regia
│   └── js/palladio.js               ← filtro unità (progressive enhancement)
├── templates/                       ← template override-abili dal tema
│   ├── single-pll_edificio.php
│   ├── single-pll_unita.php
│   └── archive-pll_edificio.php
└── includes/
    ├── class-autoloader.php         ← autoloader Palladio_*
    ├── class-palladio.php           ← orchestratore, carica i moduli
    ├── class-activator.php          ← attivazione
    ├── class-deactivator.php        ← disattivazione
    ├── core/                        ← MODULO CORE
    │   ├── class-cpt.php            ← CPT + tassonomie
    │   ├── class-meta.php           ← register_post_meta (show_in_rest)
    │   └── class-scenario.php       ← bundle/split + regola di coerenza
    ├── frontend/                    ← MODULO PRESENTER
    │   ├── class-templates.php      ← routing template (override dal tema)
    │   ├── class-assets.php         ← enqueue condizionale CSS/JS
    │   ├── class-shortcodes.php     ← [palladio_edifici]
    │   └── template-functions.php   ← helper di presentazione
    ├── leads/                       ← MODULO REGIA (dati + form)
    │   ├── class-store.php          ← tabella lead + query
    │   └── class-form.php           ← form cattura lead + notifica
    ├── admin/                       ← ADMIN (Regia, Lingue, AI)
    │   ├── class-regia.php          ← menu + dashboard + azioni
    │   ├── class-leads-list-table.php ← pipeline lead (WP_List_Table)
    │   ├── class-translations.php   ← metabox traduzioni
    │   └── class-ai.php             ← metabox AI + handler AJAX
    ├── i18n/                        ← MODULO LINGUE (pagine clone)
    │   ├── class-languages.php      ← config, lingua corrente, hreflang, switcher, filtro archivi
    │   └── class-translator.php     ← gruppi di traduzione: clone + sync dati strutturati
    ├── ai/                          ← MODULO AI (OpenAI)
    │   ├── class-crypto.php         ← cifratura chiave API (libsodium)
    │   ├── class-settings.php       ← impostazioni AI + Agent + uso/costi
    │   ├── class-openai.php         ← client HTTP (chat + tools + embeddings)
    │   └── class-composer.php       ← generazione schede + traduzioni
    ├── agent/                       ← MODULO AGENT (RAG + chat)
    │   ├── class-kb.php             ← knowledge base + ricerca semantica
    │   ├── class-chats.php          ← log conversazioni
    │   ├── class-tools.php          ← function calling
    │   ├── class-rest.php           ← endpoint /agent/chat + orchestrazione
    │   └── class-widget.php         ← widget frontend
    └── feeds/                       ← MODULO FEEDS (distribuzione)
        ├── class-manager.php        ← endpoint token + cache + cron + admin
        ├── class-adapter.php        ← base astratta (mappatura campi)
        ├── class-kyero.php          ← export Kyero v3 (XML)
        └── class-csv.php            ← export CSV generico
```

I moduli sono attivabili/disattivabili via filtro `palladio/modules`, per installazioni leggere.

## Requisiti

- WordPress 6.4 o superiore.
- PHP 8.1 o superiore.

## Installazione

1. Copia la cartella `palladio` in `wp-content/plugins/`.
2. Attiva **Palladio** da **Plugin**.
3. Crea il primo **Edificio** dal menu Palladio, poi le **Unità** collegate.

## Roadmap

Il modulo Core è il primo mattone dell'MVP (Fase 0). Le fasi successive — Presenter, Composer/AI, Agent, Regia, Feeds — sono descritte nella §7 del documento di progetto.

## Licenza

Distribuito sotto licenza GPL v2 o successiva.
