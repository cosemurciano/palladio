# Palladio

Sistema WordPress per la vendita frazionata di immobili: **un edificio, molte unità, una campagna**.

Palladio trasforma un sito WordPress in un sistema di regia completo per la vendita di unità immobiliari appartenenti a uno o più edifici. La visione di prodotto completa, il modello dati e la roadmap sono in [`PALLADIO-Progetto.md`](PALLADIO-Progetto.md).

## Stato

**Versione 0.1.0 — scaffold del modulo Core.** Il plugin è installabile e attivabile: registra il modello dati (CPT, tassonomie, meta) descritto nella §3 del documento di progetto. I moduli AI, i18n, Presenter, Regia e Feeds sono ancora da implementare (cfr. Roadmap §7).

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

### Architettura

```
palladio/
├── palladio.php                     ← bootstrap, costanti, hook
├── uninstall.php                    ← cleanup opzioni/capability (non i contenuti)
└── includes/
    ├── class-autoloader.php         ← autoloader Palladio_*
    ├── class-palladio.php           ← orchestratore, carica i moduli
    ├── class-activator.php          ← attivazione
    ├── class-deactivator.php        ← disattivazione
    └── core/                        ← MODULO CORE
        ├── class-cpt.php            ← CPT + tassonomie
        ├── class-meta.php           ← register_post_meta (show_in_rest)
        └── class-scenario.php       ← bundle/split + regola di coerenza
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
