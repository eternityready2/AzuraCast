# FRONTEND KNOWLEDGE BASE

## OVERVIEW
`frontend/` contains the Vue 3 multi-page client: domain components, reusable UI, composables, TS entities, styles, and Vite entrypoints.

## STRUCTURE
```text
frontend/
├── components/
│   ├── Common/    # Shared reusable UI primitives and tables
│   ├── Admin/     # Admin-facing feature areas
│   ├── Stations/  # Per-station management features
│   ├── Public/    # Public player, requests, podcasts, WebDJ
│   ├── Account/   # User account/security screens
│   ├── Setup/     # First-run setup UI
│   └── Layout/    # App shell pieces
├── functions/     # Shared composables and helper logic
├── entities/      # TS interfaces and API models
├── js/pages/      # Vite page entrypoints
├── scss/          # Theme/bootstrap styling
└── vendor/        # Local wrappers around third-party packages
```

## WHERE TO LOOK
| Task | Location | Notes |
|------|----------|-------|
| Shared widgets | `components/Common/` | Tables, forms, modals, generic controls |
| Station feature UI | `components/Stations/` | Largest and most complex feature tree |
| Admin feature UI | `components/Admin/` | Config-heavy forms and management screens |
| Composables | `functions/` | `use*` helpers, query providers, modal/table abstractions |
| API shapes | `entities/` | Shared TS types; `ApiInterfaces.ts` is especially large |
| Page bootstraps | `js/pages/` | Each file becomes a build entry |
| Shared shell | `layout.ts`, `js/layout.js` | Frontend bootstrap and common setup |

## CONVENTIONS
- Use Vue 3 with TypeScript and composition-style `use*` helpers; repo tooling is set up for strict TS checks even though some escape hatches exist.
- Expect a multi-page architecture. New screens usually start from `frontend/js/pages/`, not from a single global router root.
- There is no canonical `App.vue` + `main.ts` SPA entry here; shared bootstrapping lives in `layout.ts`, and backend-rendered pages mount Vue instances individually.
- Path aliases matter: `~/*` points at `frontend/*`, `!/*` points at repo root.
- Shared feature form code typically lives under `Form/`, with deeply shared pieces under `Form/Common/`.
- Common reusable primitives belong in `components/Common/` before introducing near-duplicates inside feature folders.
- Vendor wrappers in `frontend/vendor/` are intentional seams for third-party integration setup.

## ANTI-PATTERNS
- Do not grow new god-components like `components/Stations/AiNews.vue`; split features before they reach that scale.
- Do not scatter one-off API typings if a domain type already exists in `entities/`.
- Do not bypass lint/type issues casually with `eslint-disable` or `@ts-expect-error`; existing ones are debt, not precedent.
- Do not treat `web/static/vite_dist/` as editable source.

## UNIQUE STYLES
- `components/Stations/` and `components/Admin/` mirror backend domains closely.
- `functions/dataTable/` centralizes provider patterns for table-backed screens.
- Public-facing experiences (`Public/Player`, `Public/WebDJ`, podcasts) are first-class feature trees, not demos or thin wrappers.
- `ApiInterfaces.ts` acts as a broad contract surface; changes there usually ripple widely.

## TESTING
- There is no first-party JS test suite in this repo today.
- Prefer the smallest verification scope that proves your change. For localized frontend edits, run lint or other checks against the touched file(s) first instead of `npm run lint` or `npm run tsc` for the whole project.
- Avoid whole-project lint/typecheck runs for single-file or narrow feature work unless the user explicitly asks for them, the change is cross-cutting, or you are preparing a final pre-merge/full-verification pass.
- When broader verification is needed, explain why the wider scope is justified and note that repo-wide `lint`/`tsc` can fail on unrelated pre-existing issues.
- Minimum verification for frontend work is usually scoped lint/build evidence for the touched area, and if relevant a targeted browser/manual check; reserve `npm run lint`, `npm run tsc`, and `npm run build` for broader changes or explicit requests.