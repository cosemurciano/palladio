# Palladio

Sistema WordPress per la vendita frazionata di immobili: **un edificio, molte unità, una campagna**.

Palladio trasforma un sito WordPress in un sistema di regia completo per la vendita di unità immobiliari appartenenti a uno o più edifici. La visione di prodotto completa, il modello dati e la roadmap sono in [`PALLADIO-Progetto.md`](PALLADIO-Progetto.md).

## Stato

**Versione 0.2.0 — Core + Presenter (frontend) con integrazione PoeTheme.** Il plugin è installabile e attivabile: registra il modello dati (CPT, tassonomie, meta) e renderizza le pagine di edificio e unità sul front-end, integrandosi nativamente con [PoeTheme](https://github.com/cosemurciano/PoeTheme). I moduli AI, i18n, Regia e Feeds sono ancora da implementare (cfr. Roadmap §7).

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
    └── frontend/                    ← MODULO PRESENTER
        ├── class-templates.php      ← routing template (override dal tema)
        ├── class-assets.php         ← enqueue condizionale CSS/JS
        ├── class-shortcodes.php     ← [palladio_edifici]
        └── template-functions.php   ← helper di presentazione
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
