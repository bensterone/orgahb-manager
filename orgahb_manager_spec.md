# OrgaHB Manager — Canonical Specification (Handbook-First Revision)
Version: 2.0  
Status Baseline: Day 0 (no code, no generated assets, no pre-existing project files assumed)  
Document Purpose: This is the single authoritative specification for product scope, architecture, data model, dependency policy, WordPress lifecycle behavior, and implementation guardrails.

---

## 1. Executive Summary

**OrgaHB Manager** is a self-hosted WordPress plugin for running a **building-specific organizational handbook** inside a private intranet or controlled staff portal.

It is an **organizational handbook first**.

It is **not** a CRM suite, not a property-management platform, not a ticketing system, not a dispatch tool, and not a recurring-maintenance planner.

The core promise is deliberately narrower and clearer:

> **A staff member scans one QR code for a building, opens the building-specific handbook bundle, navigates by area, reads the right instruction, opens the right process or document, and can optionally leave auditable evidence or an operational observation.**

That product promise is intentionally different from:
- tenant communication platforms,
- repair-ticket systems,
- apartment-level complaint suites,
- maintenance planning tools,
- and all-in-one property CRMs.

The plugin exists to answer:

1. What is this building?
2. Which handbook content applies here?
3. Which process or controlled document should the operator open?
4. What proof or note should be left after reading or acting?

This specification rewrites the product around that handbook-first boundary.

---

## 2. Product Thesis and Strategic Boundary

### 2.1 Product Thesis

The correct positioning is:

**“The building-specific digital organizational handbook with QR access for staff in the field.”**

This means:
- the building is the stable physical anchor,
- the handbook bundle is the stable informational anchor,
- and the QR code is the stable entry point.

### 2.2 What the Product Is

The product is:
- a structured organizational handbook,
- a controlled-document layer,
- a process-visualization layer,
- a building-specific knowledge bundle,
- an acknowledgment and evidence layer,
- and a lightweight operational note layer.

### 2.3 What the Product Is Not

The product is not:
- a tenant CRM,
- a property-administration suite,
- a helpdesk,
- a maintenance ERP,
- a contractor dispatch system,
- a recurring-task scheduler,
- or a unit/apartment complaint platform.

### 2.4 Competitive Discipline

The product must remain complementary to existing real-estate / CRM / ticketing systems rather than competing head-on with them.

The intended operating model is:

- **OrgaHB** holds the building-specific handbook truth.
- External systems may hold tenant communication, repair tickets, work orders, invoices, or service history.
- OrgaHB may later link out to those systems or store an external reference, but it must not try to replace them in the baseline.

### 2.5 Core Market

Primary market:
- housing cooperatives,
- property teams,
- facility-oriented operations teams,
- caretaker teams,
- internal service teams working across building common areas.

Secondary market:
- SMEs with mixed office/field procedures where a single physical place needs a contextual handbook.

---

## 3. Scope Rings

This is a **scope contract**, not an implementation-status report.

### 3.1 Ring A — Core Build Scope

The first codebase must fully support:

1. handbook pages,
2. process diagrams with hotspots,
3. controlled documents (PDF + DOCX),
4. building records,
5. building-specific area navigation,
6. one immutable QR entry point per building,
7. building-to-content bundle linking,
8. acknowledgments,
9. immutable field evidence logging,
10. lightweight building observations,
11. approval workflow,
12. review reminders,
13. deterministic search,
14. audit / compliance exports,
15. SVG-safe diagram handling,
16. WordPress-native lifecycle behavior,
17. uninstall backup generation as an explicit admin action,
18. role-based access,
19. mobile building view,
20. basic “report outdated / report observation” feedback.

### 3.2 Ring B — Approved Immediate Expansion Scope

The following are designed for now, but must not block the first vertical slice:

1. CSV import for buildings and building bundle links,
2. bulk document import,
3. optional building-local offline queue,
4. optional building assignment restrictions by user,
5. building bundle templates / presets,
6. outbound webhook or deep-link support to external CRM or ticket systems,
7. optional secondary asset QR codes for major technical equipment,
8. richer revision diff UI,
9. stronger synonym-aware search tuning.

### 3.3 Ring C — Explicitly Deferred

The following are deliberately excluded from the baseline:

1. tenant master data,
2. apartment / unit-level complaint handling,
3. native ticket queues,
4. SLA handling,
5. contractor dispatch,
6. recurring task / due-work engine,
7. work-order planning,
8. invoice or vendor workflows,
9. native BPMN XML rendering or editing,
10. Mammoth.js or DOCX-to-HTML conversion,
11. AI / RAG assistant,
12. organigram editor,
13. full CAFM / CMMS functionality,
14. native mobile apps,
15. public unauthenticated access,
16. SSO / LDAP platform built from scratch,
17. generic workflow designer.

### 3.4 Scope Heuristic

If a proposed feature makes the product feel more like:
- property management,
- repair dispatch,
- tenant service,
- or task orchestration,

then it is probably out of scope.

If a proposed feature makes the product better at:
- showing the right content for a building,
- proving that someone read or followed it,
- or capturing a lightweight operational note,

then it is probably in scope.

---

## 4. Product Principles

### 4.1 Organizational Handbook First
Every major design choice must strengthen the handbook use case before anything else.

### 4.2 One Stable Doorway Per Building
The baseline physical model is **one QR code per building**, not one QR code per process and not many stickers for every area.

### 4.3 Areas Are Navigation, Not Separate Products
Areas such as stairwell, heating room, outside area, roof access, or waste area are navigation groups inside the building bundle, not separate CRM entities and not separate baseline QR objects.

### 4.4 Content Before Workflow
The product should first answer “what do I need to read or open here?” rather than “which ticket should I process next?”

### 4.5 Evidence, Not Task Management
Field evidence is allowed and valuable. A full due/overdue scheduling engine is intentionally not part of the baseline.

### 4.6 Common Areas, Not Apartments
The product is about buildings and their shared/common operational areas. It is not about apartments or tenant-private spaces.

### 4.7 Deterministic Before Intelligent
Strong metadata, stable links, reproducible exports, and predictable search come before any “smart” layer.

### 4.8 Low Dependency Count
Every dependency must justify itself through security, usability, or substantial implementation simplification.

### 4.9 SVG Is a First-Class Runtime Format
SVG is required because building/process diagrams often originate from tools like draw.io / diagrams.net. SVG is accepted only after sanitization.

### 4.10 DOCX Is a Controlled File
DOCX is stored, versioned, downloadable/openable, and may require acknowledgment. It is not converted into HTML in the baseline.

### 4.11 Complement, Do Not Replace
Where a property-management or CRM suite already exists, OrgaHB should complement it with structured building handbook content, not try to absorb its entire scope.

---

## 5. Real-World Operating Model

### 5.1 The Building Is the Primary Object

The real-world object of organization is the **building**.

A building may contain operational areas such as:
- entrance,
- stairwell,
- basement,
- heating / utility,
- roof access,
- outside area,
- waste area,
- laundry room,
- bike room,
- garage,
- shared technical area.

These areas are contextual groupings for content.

### 5.2 The QR Code Identifies the Building

The QR code should answer:
- “I am at this building”
- not “I am at process X”
- and not “I am opening ticket Y”

That means the QR opens a **building handbook landing page**.

### 5.3 The Building Bundle

A building bundle is the curated set of handbook content that applies to one building.

It may include:
- narrative handbook pages,
- process diagrams,
- controlled documents,
- emergency instructions,
- building-specific local notes,
- building-specific contacts,
- building-specific observations,
- and optionally linked external references.

### 5.4 The Area Layer

Inside the building, the operator can navigate by area, for example:
- Treppenhaus,
- Heizung / Technik,
- Keller / Nebenräume,
- Außenbereich,
- Müllplatz,
- Dachzugang.

This keeps the physical model intuitive without forcing many QR stickers into the building.

### 5.5 Why One QR Per Building Is the Baseline

This specification rejects the earlier idea of many QRs per building because that pushes the product toward operational task-routing complexity and sticker sprawl.

The baseline must remain:
- easy to explain,
- easy to install,
- easy to maintain,
- and obviously handbook-centric.

### 5.6 Optional Future Exceptions

A second QR may later make sense for:
- a separate building entrance in a large site,
- a major technical asset,
- or a security-sensitive room.

But that is an exception, not the baseline.

---

## 6. Primary Personas

### 6.1 Reader
Reads handbook pages and controlled documents, searches, and acknowledges current revisions.

### 6.2 Field Operator
Uses a phone or tablet on site, scans the building QR, navigates by area, opens a process or document, and may log evidence or an observation.

### 6.3 Editor
Creates and updates pages, processes, documents, and building bundles; maintains metadata and local building notes.

### 6.4 Reviewer
Approves content, monitors review dates, checks audit exports, and ensures the handbook remains current.

### 6.5 Administrator
Controls settings, permissions, privacy hooks, uninstall behavior, and other irreversible or security-sensitive actions.

### 6.6 Office Coordinator (Optional Role Pattern)
May enter a building-level operational note based on an incoming phone call, email, or external complaint, without using OrgaHB as the full complaint-management system.

---

## 7. Canonical User Journeys

### 7.1 Reading and Acknowledgment

1. User opens the handbook or a building bundle.
2. User finds a page or document through tree, building view, or search.
3. Metadata header shows version, validity, owner, and acknowledgment state.
4. User acknowledges the current revision if required.
5. System stores an immutable acknowledgment event tied to the approved revision.

### 7.2 Building Setup

1. Editor creates a building record.
2. Editor defines the building’s areas (for example: stairwell, basement, heating, outside area).
3. Editor links relevant pages, processes, and documents into those areas.
4. Editor adds building-specific notes, contacts, and emergency information where needed.
5. Editor generates and prints the single building QR code.
6. Reviewer approves affected content if workflow rules require it.

### 7.3 Scan and Navigate

1. Field operator arrives at the building.
2. Operator scans the building QR.
3. The building landing page opens in mobile mode.
4. Operator sees:
   - building title,
   - optional address and short code,
   - area navigation,
   - featured content,
   - important documents,
   - recent observations.
5. Operator taps the relevant area and opens the needed process or document.

### 7.4 Process Use and Evidence

1. Operator opens a process diagram from the building bundle.
2. Diagram is zoomable and touch-friendly.
3. Operator taps a step hotspot.
4. A bottom sheet shows step help text and recent history.
5. Operator optionally logs an evidence event:
   - completed,
   - issue noted,
   - blocked,
   - not applicable,
   - escalated.
6. System stores an immutable execution row tied to process, hotspot, building, area, revision, and user.

### 7.5 Building Observation from a Real-Life Complaint

Example:
A tenant or external caller says:
- “The waste area is overflowing.”
- “The stairwell lighting on the common landing is not working.”
- “There is a strong smell in the basement corridor.”

OrgaHB must not become the complaint-management master.

Instead:

1. Staff member opens the building bundle or admin screen.
2. Staff member creates a lightweight **building observation**.
3. The observation is tagged to:
   - building,
   - optional area,
   - category,
   - summary,
   - optional external reference.
4. The observation appears in the building context for staff.
5. If an external CRM or ticket system exists, the office may store its external reference.
6. OrgaHB remains the handbook / context layer, not the end-to-end complaint workflow engine.

### 7.6 Audit Review

1. Reviewer opens reports.
2. Reviewer filters by building, content type, date range, user, revision, or event type.
3. Reviewer exports CSV or opens a print-optimized report view.
4. Reviewer can prove:
   - who acknowledged which revision,
   - who logged which evidence,
   - which building observations were created or resolved,
   - and what content was linked to which building.

---

## 8. Technical Baseline

### 8.1 Platform

- WordPress **6.8+** minimum
- target the current maintained WordPress release during active development
- PHP **8.1+**
- MySQL 8+ or compatible MariaDB release
- Gutenberg for narrative page content

### 8.2 JavaScript Runtime Model

- React via WordPress’s bundled React (`@wordpress/element`)
- `wp.apiFetch`
- `wp.i18n`
- Vite-based developer build
- no Node.js requirement on the production server

### 8.3 Packaging Model

- built JS/CSS artifacts are committed to the release artifact
- third-party browser assets are vendored locally
- Composer dependencies are allowed only for permissively licensed PHP libraries
- the release ZIP must be deployable without local build tooling on the server

### 8.4 Rendering Model

The plugin should prefer WordPress-native server-rendered entry pages with targeted React islands / SPA shells where they actually help, rather than turning the entire plugin into an unnecessary front-end framework project.

---

## 9. Dependency and License Policy

### 9.1 License Allow List

New runtime dependencies are allowed only if they are both:

1. compatible with a WordPress-distributed plugin, and
2. under a permissive license such as:
   - MIT
   - Apache-2.0
   - BSD-2-Clause
   - BSD-3-Clause
   - ISC
   - 0BSD
   - public-domain / similarly permissive reviewed terms

### 9.2 Disallowed by Default

Do not add new runtime dependencies under:

- GPL-only,
- AGPL,
- SSPL,
- MPL,
- source-available but non-open licenses,
- custom commercial licenses,
- unclear licensing,
- or licenses that create unnecessary packaging ambiguity.

### 9.3 Decision Rule

A dependency is acceptable only if it does at least one of the following:

- materially reduces implementation complexity,
- materially improves security,
- materially improves field usability,
- or removes brittle custom code that would otherwise be costly to maintain.

Otherwise, prefer WordPress APIs, browser APIs, or small handwritten code.

### 9.4 Runtime-Target Rule

Do not add diagram-language runtimes, CRM SDKs, or workflow engines into the baseline unless they are essential to the building-handbook promise.

---

## 10. Approved Dependency Matrix

This is the canonical dependency policy for the codebase.

### 10.1 Mandatory / Core Runtime Dependencies

| Purpose | Decision | Library / Approach | License | Notes |
|---|---|---|---|---|
| JS build | Keep | Vite + `@vitejs/plugin-react` | MIT | developer build only |
| Search | Keep | Fuse.js | Apache-2.0 | deterministic client-side search |
| Hotspot editor | Keep | Interact.js | MIT | reliable drag/resize/touch handling |
| PDF viewing | Keep | PDF.js via `pdfjs-dist` | Apache-2.0 | vendor generic viewer locally |
| QR generation | Keep | `qrcode` | MIT | admin/browser generation only |
| SVG sanitization | Keep | `rhukster/dom-sanitizer` | MIT | PHP sanitization baseline |
| Diagram pan/zoom | Keep | Panzoom | MIT | strongly justified for mobile diagram use |

### 10.2 Optional Approved Dependencies

| Purpose | Status | Library / Approach | License | Use Rule |
|---|---|---|---|---|
| Offline queue helper | Approved optional | `idb-keyval` | Apache-2.0 | only if low-connectivity queue is enabled |
| Rich revision diff UI | Approved optional | `diff` (jsdiff package) | BSD-3-Clause | use only if native compare is insufficient |
| Date picker fallback | Approved optional | `flatpickr` | MIT | use only if native date inputs prove inadequate |
| Client-side PDF generation | Approved optional | `pdf-lib` | MIT | only if browser print is not enough |

### 10.3 Explicit Rejections

| Rejected Choice | Reason |
|---|---|
| Mammoth.js | intentionally out of scope; expands DOCX handling too far |
| `bpmn-js` | unnecessary for static visual diagram workflow |
| React Router | unnecessary SPA complexity for the baseline |
| global state libraries (Redux, Zustand, etc.) | avoid unless genuine complexity later demands it |
| Axios | `wp.apiFetch` already solves the problem |
| non-permissive server-side PDF generators | avoid in baseline |
| CRM / ticketing SDKs | not aligned with product boundary |
| Mermaid / PlantUML runtime rendering in baseline | future authoring convenience at most; not baseline runtime |

### 10.4 Native APIs Preferred

Use native platform capabilities where reasonable:

- `crypto.randomUUID()` in JS and `wp_generate_uuid4()` in PHP,
- native `<input type="date">` first,
- browser `window.print()` for print-to-PDF flows,
- `IntersectionObserver`,
- `URLSearchParams`,
- CSS sticky / transforms / safe-area insets.

---

## 11. File and Directory Blueprint

```text
orgahb-manager/
├── orgahb-manager.php
├── uninstall.php
├── readme.txt
├── package.json
├── package-lock.json
├── vite.config.js
├── composer.json
├── composer.lock
│
├── includes/
│   ├── class-plugin.php
│   ├── class-install.php
│   ├── class-cpt.php
│   ├── class-taxonomy.php
│   ├── class-capabilities.php
│   ├── class-rest-api.php
│   ├── class-metaboxes.php
│   ├── class-acknowledgments.php
│   ├── class-executions.php
│   ├── class-observations.php
│   ├── class-workflow.php
│   ├── class-cron.php
│   ├── class-buildings.php
│   ├── class-building-links.php
│   ├── class-qr.php
│   ├── class-audit-log.php
│   ├── class-search.php
│   ├── class-feedback.php
│   ├── class-export.php
│   ├── class-privacy.php
│   └── class-settings.php
│
├── admin/
│   ├── class-admin.php
│   └── views/
│       ├── page-reports.php
│       ├── page-settings.php
│       ├── page-print-report.php
│       └── page-backup-export.php
│
├── src/
│   ├── admin-shell/
│   │   ├── main.jsx
│   │   ├── App.jsx
│   │   ├── hooks/
│   │   └── components/
│   │
│   ├── process-editor/
│   │   └── main.js
│   │
│   ├── handbook-viewer/
│   │   └── main.jsx
│   │
│   └── shared/
│       ├── api.js
│       ├── constants.js
│       ├── search.js
│       ├── utils.js
│       ├── offline.js
│       ├── field-mode.js
│       └── styles/
│
├── assets/
│   ├── dist/
│   └── vendor/
│       └── pdfjs/
│
├── templates/
│   └── building-view.php
│
├── vendor/
│   └── ...composer dependencies...
│
└── languages/
```

### 11.1 Important Packaging Rules

1. PDF.js assets are vendored locally, not fetched from a CDN.
2. The release ZIP contains all built assets needed for deployment.
3. `uninstall.php` exists at plugin root and is responsible only for explicit uninstall deletion logic.
4. Backup generation is an admin action, not something the plugin tries to perform during the uninstall request itself.

---

## 12. WordPress Plugin Best Practices and Lifecycle Rules

### 12.1 Main Plugin File Responsibilities

The main plugin file must:
- contain the plugin header,
- register activation hook,
- register deactivation hook,
- bootstrap the plugin classes,
- and load the text domain.

Activation/deactivation hooks must be registered from the main plugin file, not hidden deep in later execution paths.

### 12.2 Activation

Activation must:
- register custom post types and taxonomies early enough for flush-safe rewrites,
- create or update custom tables using `dbDelta()` where appropriate,
- store / update a plugin schema version option,
- add custom capabilities,
- register default options,
- and flush rewrite rules once.

### 12.3 Deactivation

Deactivation must:
- unschedule plugin cron jobs,
- flush rewrite rules if custom rewrite rules were registered,
- and perform only temporary cleanup.

Deactivation must **not** delete plugin data.

### 12.4 Uninstall

Permanent deletion belongs in root `uninstall.php`.

`uninstall.php` must:
- guard with `WP_UNINSTALL_PLUGIN`,
- check the saved “delete plugin data on uninstall” setting,
- and only then remove plugin-owned tables, options, and capabilities.

### 12.5 Backup Before Uninstall

The plugin must provide an explicit admin action:
- **Create uninstall backup**

This produces a downloadable **ZIP** archive.

The baseline ZIP should contain:
- `manifest.json`
- `settings.json`
- `content-pages.json`
- `content-processes.json`
- `content-documents.json`
- `buildings.json`
- `building-links.json`
- `acknowledgments.ndjson`
- `executions.ndjson`
- `observations.ndjson`
- `audit-events.ndjson`
- `attachments.csv`

Optional inclusion of actual file binaries may be offered as a separate checkbox because that can dramatically increase archive size.

### 12.6 Settings API

Plugin settings must use the WordPress Settings API / Options API rather than ad-hoc custom persistence patterns.

### 12.7 Privacy Hooks

Because the plugin stores staff-related personal data in custom tables, it must register:
- privacy policy text hooks,
- a personal-data exporter,
- and an eraser / anonymization policy for data that is appropriate to anonymize.

### 12.8 Deleted User Handling

For immutable audit records, deletion must not silently break the audit trail.

When a user is deleted:
- custom-table user references should be set to `NULL` or a neutral value,
- a historical display label must remain in the row,
- and the current active WordPress identity is not retained unnecessarily.

This is a data-minimization and audit-preservation compromise.

### 12.9 Internationalization

All user-facing strings must be translation-ready using WordPress i18n functions and the plugin text domain `orgahb-manager`.

### 12.10 Charset / Collation

Custom tables must be created using `$wpdb->get_charset_collate()` so they follow the WordPress installation’s charset/collation settings.

### 12.11 Security Basics

All executable PHP files must have an `ABSPATH` guard where appropriate.

`uninstall.php` uses `WP_UNINSTALL_PLUGIN` instead.

### 12.12 Admin and REST Safety

Every mutating admin action or REST endpoint must check:
- nonce where appropriate,
- user capability,
- object-level access if needed,
- and sanitized input.

### 12.13 HTTP and Email APIs

Use WordPress APIs:
- `wp_mail()` for email,
- `wp_remote_get()` / `wp_remote_post()` and related wrappers for HTTP requests,
- not raw ad-hoc cURL wrappers in plugin business logic.

### 12.14 Readme

A root `readme.txt` must exist and follow normal WordPress plugin conventions.

---

## 13. Product Modules

1. **Handbook Pages**
2. **Process Diagrams**
3. **Controlled Documents**
4. **Buildings**
5. **Building Area Navigation**
6. **Building Bundle Linking**
7. **QR Entry**
8. **Acknowledgments**
9. **Field Evidence**
10. **Observations**
11. **Workflow + Reviews**
12. **Search**
13. **Reports / Export**
14. **Backup / Uninstall Safety**
15. **Feedback Loop**

---

## 14. Content Model

### 14.1 `orgahb_page`
Narrative handbook content authored in Gutenberg.

Use for:
- SOPs,
- policies,
- explanations,
- onboarding content,
- internal reference pages,
- and building-independent knowledge.

### 14.2 `orgahb_process`
A notation-agnostic process visual represented by an uploaded image asset plus hotspot JSON.

Use for:
- flowcharts,
- Ablaufdiagramme,
- BPMN-like diagrams,
- operational maps,
- escalation flows,
- and guided procedures.

The baseline runtime is image/SVG based. The plugin does not promise semantic BPMN execution.

### 14.3 `orgahb_document`
A controlled file record whose current approved file is the authoritative version.

Use for:
- PDF,
- DOCX,
- controlled document metadata,
- version labels,
- and acknowledgments.

### 14.4 `orgahb_building`
A building-specific handbook entry point.

Use for:
- building title and address,
- building QR token,
- building-specific notes,
- local contacts,
- emergency information,
- building area definitions,
- and the linked bundle of relevant content.

### 14.5 `orgahb_section` Taxonomy
A reusable conceptual handbook taxonomy.

Use for:
- policy grouping,
- topic grouping,
- cross-cutting classification across content types.

This taxonomy is conceptual and global, not a substitute for building-local area navigation.

### 14.6 Building Areas Are Not a CPT

Building areas are defined as structured metadata on the building record rather than as separate posts.

Reason:
- areas are local to a building,
- labels may vary by building,
- the baseline does not need a heavy secondary content model,
- and the product should avoid turning each physical sub-area into its own pseudo-application.

---

## 15. Building Bundle Model

### 15.1 Definition

A **building bundle** is the curated set of pages, processes, and documents linked to a building.

### 15.2 Why This Model Exists

This model is the heart of the handbook-first rewrite.

The building QR must open a curated contextual bundle, not:
- a process queue,
- a ticket list,
- or a maintenance scheduler.

### 15.3 Bundle Assignment Unit

The atomic unit is:

**Building + area + content item**

An item may be linked to:
- zero buildings,
- one building,
- or many buildings.

The same global SOP or process may therefore be reused across many buildings.

### 15.4 Area Key Rules

Each building defines stable area keys such as:
- `general`
- `stairwell`
- `heating`
- `basement`
- `outside`
- `waste`
- `roof_access`

Requirements:
- area keys must be stable and slug-like,
- area labels may be edited,
- sort order is stored explicitly,
- a default `general` area must always exist.

### 15.5 What a Link Can Contain

A building bundle link may carry:
- content type,
- content ID,
- area key,
- sort order,
- featured flag,
- optional building-local note,
- optional advisory interval label.

### 15.6 Advisory Interval Is Informational Only

If present, an advisory interval label is for contextual guidance only, for example:
- “usually checked weekly”
- “seasonally relevant”
- “review after contractor visit”

It is **not** a recurring task engine and must not create due / overdue states in the baseline.

### 15.7 Building Landing Page Requirements

After scanning, the building page must show:

- building title,
- optional address,
- optional short code,
- area navigation,
- featured content,
- important documents,
- emergency / contact information,
- recent observations,
- optional quick search within the building bundle,
- and a clear path back to the full handbook.

### 15.8 No Task Dashboard

The building page must not try to mimic a maintenance dashboard with open task counts, service queues, or dispatch statuses.

It remains a contextual handbook page.

---

## 16. Shared Metadata Model

The metadata model must be explicit and stable.

### 16.1 Common Meta Fields

| Key | Type | Applies To | Purpose |
|---|---|---|---|
| `_orgahb_owner_user_id` | int | page/process/document/building | primary owner |
| `_orgahb_deputy_user_id` | int | page/process/document/building | deputy / fallback |
| `_orgahb_owner_label` | string | all content | display fallback if no user |
| `_orgahb_valid_from` | date | page/process/document | effective start |
| `_orgahb_valid_until` | date | page/process/document | optional expiry |
| `_orgahb_next_review` | date | page/process/document/building | next review date |
| `_orgahb_version_label` | string | page/process/document | human-readable version |
| `_orgahb_change_log` | longtext | page/process/document | editor-authored change note |
| `_orgahb_requires_ack` | bool | page/document | acknowledgment required |
| `_orgahb_search_aliases` | string | all content | comma-separated aliases |
| `_orgahb_archived_reason` | string | all content | optional archive rationale |

### 16.2 Process-Specific Meta

| Key | Type | Purpose |
|---|---|---|
| `_orgahb_process_image_id` | int | current visual asset attachment |
| `_orgahb_hotspots_json` | longtext | hotspot definitions |
| `_orgahb_diagram_notation` | string | e.g. `flowchart`, `bpmn_like`, `swimlane`, `check_sequence` |
| `_orgahb_source_format` | string | e.g. `svg`, `png`, `jpg`, `drawio_export` |
| `_orgahb_source_attachment_id` | int/null | optional original editable/source file attachment |
| `_orgahb_is_field_executable` | bool | whether step hotspots can log evidence |

### 16.3 Document-Specific Meta

| Key | Type | Purpose |
|---|---|---|
| `_orgahb_current_attachment_id` | int | current approved file |
| `_orgahb_document_mime` | string | current file MIME |
| `_orgahb_document_size` | int | current file size |
| `_orgahb_document_display_mode` | string | `pdf_inline` or `file_open` |

Rule:
- DOCX always uses `file_open` in the baseline.

### 16.4 Building-Specific Meta

| Key | Type | Purpose |
|---|---|---|
| `_orgahb_qr_token` | string | immutable QR lookup token |
| `_orgahb_building_code` | string | short code / optional internal code |
| `_orgahb_building_address` | longtext | displayable address block |
| `_orgahb_building_contacts` | longtext | local contacts block |
| `_orgahb_emergency_notes` | longtext | emergency / quick reference text |
| `_orgahb_areas_json` | longtext | area definitions array |
| `_orgahb_building_active` | bool | allows retirement without deletion |

### 16.5 Building Area JSON Shape

Each area definition must at minimum contain:

- `key`
- `label`
- `sort_order`
- optional `description`

The `general` area must always exist.

---

## 17. Hotspot Model

### 17.1 Diagram Philosophy

A process diagram is a visual guide, not a full semantic workflow engine.

### 17.2 Hotspot Types

Each hotspot must declare a `kind`:

- `step` — executable informational step
- `link` — navigational hotspot

This removes ambiguity between “click to navigate” and “click to log evidence.”

### 17.3 Hotspot Fields

Each hotspot object contains:

| Field | Type | Required | Notes |
|---|---|---|---|
| `id` | string | yes | stable identifier |
| `label` | string | yes | visible label |
| `kind` | enum | yes | `step` or `link` |
| `x_pct` | float | yes | 0–100 |
| `y_pct` | float | yes | 0–100 |
| `w_pct` | float | yes | 0–100 |
| `h_pct` | float | yes | 0–100 |
| `sort_order` | int | yes | stable ordering |
| `target_type` | enum/null | conditional | for links: `page`, `process`, `document`, `url` |
| `target_id` | int/null | conditional | internal target |
| `target_url` | string/null | conditional | external target |
| `help_text` | string/null | optional | shown in viewer |
| `note_required` | bool | step only | forces note on evidence logging |
| `aliases` | string/null | optional | search support |

### 17.4 Coordinate Rules

- coordinates are percentage-based,
- all values are clamped to bounds before save,
- zero-area hotspots are invalid,
- overlaps are allowed but should warn in editor,
- hotspot IDs survive label/target edits.

### 17.5 Replacement-Image Rule

If the process image is replaced and the aspect ratio changes materially, the editor must force hotspot revalidation before publish.

### 17.6 No Hidden Semantic Dependency

Hotspots must not depend on internal SVG node IDs or BPMN semantics. The runtime contract is:
- rendered visual asset,
- hotspot overlay JSON,
- and optional help text.

---

## 18. Database Schema

### 18.1 Table: `wp_orgahb_acknowledgments`
Purpose: immutable acknowledgment events

```sql
CREATE TABLE wp_orgahb_acknowledgments (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  post_id               BIGINT UNSIGNED NOT NULL,
  user_id               BIGINT UNSIGNED NULL,
  historical_user_label VARCHAR(255)    NULL,
  acknowledged_at       DATETIME(6)     NOT NULL,
  post_revision_id      BIGINT UNSIGNED NOT NULL,
  post_version_label    VARCHAR(50)     NULL,
  source                VARCHAR(20)     NOT NULL DEFAULT 'ui',
  created_at            TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_post_user (post_id, user_id),
  KEY idx_post_revision (post_id, post_revision_id),
  KEY idx_acknowledged_at (acknowledged_at),
  KEY idx_user_ack (user_id, acknowledged_at)
) ENGINE=InnoDB %CHARSET_COLLATE%;
```

### 18.2 Table: `wp_orgahb_executions`
Purpose: immutable field evidence events

```sql
CREATE TABLE wp_orgahb_executions (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  post_id               BIGINT UNSIGNED NOT NULL,
  hotspot_id            VARCHAR(64)     NOT NULL,
  building_id           BIGINT UNSIGNED NOT NULL,
  area_key              VARCHAR(100)    NULL,
  user_id               BIGINT UNSIGNED NULL,
  historical_user_label VARCHAR(255)    NULL,
  outcome               VARCHAR(32)     NOT NULL,
  executed_at           DATETIME(6)     NOT NULL,
  note                  LONGTEXT        NULL,
  post_revision_id      BIGINT UNSIGNED NOT NULL,
  post_version_label    VARCHAR(50)     NULL,
  queue_uuid            CHAR(36)        NULL,
  source                VARCHAR(20)     NOT NULL DEFAULT 'ui',
  created_at            TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_post_hotspot (post_id, hotspot_id),
  KEY idx_building_exec (building_id, executed_at),
  KEY idx_area_exec (building_id, area_key, executed_at),
  KEY idx_user_exec (user_id, executed_at),
  KEY idx_revision (post_revision_id),
  KEY idx_queue_uuid (queue_uuid),
  KEY idx_outcome (outcome)
) ENGINE=InnoDB %CHARSET_COLLATE%;
```

### 18.3 Table: `wp_orgahb_observations`
Purpose: lightweight building-level operational notes

```sql
CREATE TABLE wp_orgahb_observations (
  id                         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  building_id                BIGINT UNSIGNED NOT NULL,
  area_key                   VARCHAR(100)    NULL,
  author_user_id             BIGINT UNSIGNED NULL,
  historical_author_label    VARCHAR(255)    NULL,
  category                   VARCHAR(50)     NOT NULL,
  status                     VARCHAR(20)     NOT NULL DEFAULT 'open',
  summary                    VARCHAR(255)    NOT NULL,
  details                    LONGTEXT        NULL,
  external_reference         VARCHAR(255)    NULL,
  resolved_at                DATETIME(6)     NULL,
  resolved_by_user_id        BIGINT UNSIGNED NULL,
  historical_resolver_label  VARCHAR(255)    NULL,
  source                     VARCHAR(20)     NOT NULL DEFAULT 'manual',
  created_at                 DATETIME(6)     NOT NULL,
  recorded_at                TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_building_status (building_id, status),
  KEY idx_area_status (building_id, area_key, status),
  KEY idx_created_at (created_at),
  KEY idx_external_reference (external_reference)
) ENGINE=InnoDB %CHARSET_COLLATE%;
```

### 18.4 Table: `wp_orgahb_audit_events`
Purpose: workflow, governance, administrative, and privacy-related audit trail

```sql
CREATE TABLE wp_orgahb_audit_events (
  id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  post_id              BIGINT UNSIGNED NULL,
  actor_user_id        BIGINT UNSIGNED NULL,
  historical_actor_label VARCHAR(255)  NULL,
  event_type           VARCHAR(50)     NOT NULL,
  from_status          VARCHAR(20)     NULL,
  to_status            VARCHAR(20)     NULL,
  post_revision_id     BIGINT UNSIGNED NULL,
  comment_text         LONGTEXT        NULL,
  metadata_json        LONGTEXT        NULL,
  occurred_at          DATETIME(6)     NOT NULL,
  created_at           TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_post_event (post_id, event_type),
  KEY idx_occurred_at (occurred_at),
  KEY idx_actor (actor_user_id, occurred_at)
) ENGINE=InnoDB %CHARSET_COLLATE%;
```

### 18.5 Table: `wp_orgahb_building_links`
Purpose: the building bundle relation table

```sql
CREATE TABLE wp_orgahb_building_links (
  id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  building_id             BIGINT UNSIGNED NOT NULL,
  area_key                VARCHAR(100)    NOT NULL DEFAULT 'general',
  content_type            VARCHAR(20)     NOT NULL,
  content_id              BIGINT UNSIGNED NOT NULL,
  sort_order              INT             NOT NULL DEFAULT 0,
  is_featured             TINYINT(1)      NOT NULL DEFAULT 0,
  local_note              VARCHAR(255)    NULL,
  advisory_interval_label VARCHAR(100)    NULL,
  created_at              TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at              TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_building_area_content (building_id, area_key, content_type, content_id),
  KEY idx_building_area (building_id, area_key, sort_order),
  KEY idx_content_lookup (content_type, content_id),
  KEY idx_featured (building_id, is_featured, sort_order)
) ENGINE=InnoDB %CHARSET_COLLATE%;
```

### 18.6 Table-Creation Rule

`%CHARSET_COLLATE%` is a placeholder in this specification.  
Implementation must use `$wpdb->get_charset_collate()` rather than hard-coding collation.

### 18.7 Why This Schema Fits the Narrower Product

This schema is intentionally slimmer than the earlier task/schedule model because it supports:
- handbook acknowledgments,
- evidence logging,
- observations,
- audit trails,
- and building bundle linking,

without pretending to be a maintenance planner or CRM.

---

## 19. Versioning Rules

### 19.1 Revision Identity

Compliance-relevant events must reference a stable `post_revision_id`, not an informal revision number.

### 19.2 Current Version

For pages, processes, and documents, the current approved revision is the authoritative version.

### 19.3 Document Revision File Snapshots

WordPress does not natively preserve attachment changes across revisions in the exact way this product needs.

Therefore:
- when a controlled document’s approved file changes,
- the relevant attachment pointer must be copied into revision meta,
- so old approved revisions still know which file belonged to them.

### 19.4 Process Revision Snapshots

For processes, approved revisions must preserve:
- the visual asset reference,
- the hotspot JSON,
- and relevant display metadata.

### 19.5 Meaningful Revision Rule

A new revision should exist for normal WordPress content changes, but compliance-sensitive interactions must always reference the **currently approved revision** at the time of interaction.

### 19.6 Superseded Revision Acceptance

If a field action was performed offline or under low connectivity and syncs later, the API must still accept the recently superseded approved revision ID that was current when the operator acted.

The system records the evidence against that revision instead of rejecting it.

---

## 20. Workflow Rules

### 20.1 Status Model

Use native WordPress statuses where practical:

- `draft`
- `pending`
- `publish`

Plus one custom archival status:

- `orgahb_archived`

### 20.2 Workflow Applicability

Workflow rules apply to:
- pages,
- processes,
- documents,
- and building posts where governance requires it.

### 20.3 Required Workflow Actions

The baseline workflow actions are:
- submit for review,
- approve,
- return for revision,
- archive,
- restore if allowed by role.

### 20.4 Audit Events

Every workflow action must create an audit event row.

### 20.5 Reviewer Comments

Return-for-revision actions require a reviewer comment.

---

## 21. Review and Validity Rules

### 21.1 Review Dates

Pages, processes, documents, and building records may all have a next review date.

### 21.2 Validity Dates

Pages, processes, and documents may carry:
- valid from,
- valid until.

### 21.3 Overdue Review Behavior

Review-overdue items remain visible unless archived or expired, but show a visible warning.

### 21.4 Future Effective Content

If `valid_from` lies in the future, the item is scheduled and not yet current.

---

## 22. Acknowledgment Rules

### 22.1 Applicability

Acknowledgment applies to:
- handbook pages,
- and controlled documents.

Baseline rule:
- not to buildings,
- and not to processes.

### 22.2 Semantics

An acknowledgment means:
- the user saw the current approved revision,
- that revision is recorded,
- and the record is immutable.

### 22.3 Re-Acknowledgment Trigger

A newly approved revision creates a new acknowledgment requirement if the item still requires acknowledgment.

### 22.4 UX Rules

If the user already acknowledged the current revision:
- show timestamp,
- show version,
- disable the primary acknowledge action.

---

## 23. Field Evidence Rules

### 23.1 Scope Discipline

Field evidence exists to prove that a staff member interacted with a handbook-guided step at a building.

It does **not** turn the plugin into a task engine.

### 23.2 Preconditions

Evidence logging is allowed only when:
- the process is published,
- the hotspot is `kind = step`,
- the building is active,
- the process is linked to that building bundle,
- and the user has execution permission.

### 23.3 Context

Every execution event must include:
- process ID,
- hotspot ID,
- building ID,
- optional area key,
- revision ID,
- acting user,
- time of execution,
- outcome,
- optional note.

### 23.4 Allowed Outcomes

The baseline outcomes are:

- `completed`
- `issue_noted`
- `blocked`
- `not_applicable`
- `escalated`

### 23.5 Note Rules

A note is mandatory when outcome is:
- `issue_noted`
- `blocked`
- `escalated`

A hotspot may also force note entry through `note_required = true`.

### 23.6 Immutability

Execution rows are append-only.

No UI editing and no UI deletion.

### 23.7 History Display

The step bottom sheet may show a limited recent history scoped by permission.

This is contextual memory, not a task ledger.

### 23.8 Time Semantics

For offline or delayed sync:
- `executed_at` stores the client-recorded action time,
- `created_at` stores when the server received the row.

---

## 24. Building and QR Model

### 24.1 Canonical Rule

The baseline physical rule is:

**One QR code per building.**

### 24.2 Building QR Token

Each building has a stable, opaque, immutable token.

Printed QR codes resolve by token, not mutable slug.

Canonical route pattern:

`{site_url}/handbook/building/{qr_token}`

### 24.3 Why the Building QR Is Correct

The building QR is the right mental model because:
- the building remains stable even when processes change,
- one sticker is operationally feasible,
- setup cost remains low,
- and the user experience stays informational instead of workflow-fragmented.

### 24.4 Human-Readable Labeling

Printed labels should include:
- building name,
- optional short code,
- and a human-readable fallback reference.

### 24.5 Area Navigation After Scan

Once the building page opens, the user can navigate to:
- stairwell,
- heating / technical area,
- basement,
- outside area,
- waste area,
- roof access,
- or any other locally defined area.

### 24.6 Optional Future Secondary QR Codes

Secondary QR codes are approved only later, and only for cases where physical complexity truly justifies them, for example:
- a major technical asset,
- a second secure entrance,
- or a very large multi-entrance site.

They must never become a baseline requirement for normal buildings.

### 24.7 No QR Per Process

The baseline explicitly rejects “one QR per process.”

---

## 25. Observations Model

### 25.1 Purpose

Observations are lightweight operational notes linked to a building and optionally an area.

They exist to capture:
- a real-world note,
- a common-area issue,
- a local irregularity,
- or a context reminder.

### 25.2 Examples

Valid examples:
- “Waste area overflowing”
- “Stairwell lighting defective”
- “Basement corridor smells damp”
- “Roof access door often left unlocked”
- “Heating room access blocked by contractor materials”

### 25.3 What Observations Are Not

Observations are not:
- full complaint tickets,
- tenant communication threads,
- service-order objects,
- SLA items,
- or apartment-level case files.

### 25.4 Sources

An observation may be created from:
- field entry in the building view,
- admin entry,
- or an external communication that gets abstracted into building-level operational terms.

### 25.5 External References

If another system holds the real ticket or communication history, OrgaHB may store:
- an external reference ID,
- or a URL / external link,

but OrgaHB does not become the master complaint system.

### 25.6 Status Model

The baseline observation status model is intentionally small:

- `open`
- `resolved`

### 25.7 Resolution

Resolving an observation is a lightweight state change, not a full workflow engine.

Resolution may optionally add:
- resolver,
- resolution timestamp,
- and a short resolution comment through audit metadata.

### 25.8 Privacy Rule

Observation text should avoid unnecessary tenant-personal data.

The preferred content is the operational abstraction, not the caller biography.

---

## 26. Search Model

### 26.1 Search Philosophy

Search is deterministic and metadata-driven.

There is no AI search layer in the baseline.

### 26.2 Search Surfaces

The plugin has two search surfaces:

1. **Global handbook search**
2. **Building-local bundle search**

### 26.3 Global Search Index

The server generates a normalized search document list for:
- pages,
- processes,
- documents,
- buildings,
- section terms,
- aliases.

The client loads this list and builds a Fuse.js index in memory.

### 26.4 Building-Local Search

After scanning a building, the building page may expose a local search box that searches only within that building bundle.

This is a very strong UX fit for field users because they often know:
- the building,
- but not the exact content title.

### 26.5 Indexed Fields

Baseline indexed fields may include:
- title,
- excerpt / summary,
- aliases,
- section labels,
- building name,
- building code,
- area labels,
- local notes,
- version label,
- document filename,
- process hotspot labels and aliases.

### 26.6 Controlled Document Search Limits

In the baseline:
- PDFs and DOCX are reliably searchable by metadata and title,
- not by full extracted body text unless an explicit later extraction feature is added.

This avoids pretending that full content search exists when it does not.

### 26.7 Cache Invalidation

Search index cache must be invalidated when:
- relevant content is published / archived / restored,
- metadata affecting search changes,
- building links change,
- or building area definitions change.

---

## 27. Document Management Rules

### 27.1 Controlled File Types

The baseline controlled document types are:
- PDF
- DOCX

### 27.2 Authoritative Version

The current approved attachment is the authoritative file for the current approved document revision.

### 27.3 Display Modes

Allowed display modes:
- `pdf_inline`
- `file_open`

Rule:
- PDF may use inline viewer
- DOCX uses `file_open` in the baseline

### 27.4 PDF Viewer

PDF viewing uses local PDF.js assets.

### 27.5 DOCX Policy

DOCX is:
- stored,
- versioned,
- downloadable/openable,
- and may require acknowledgment.

DOCX is **not** converted to HTML and not semantically rendered in-browser in the baseline.

### 27.6 Acknowledgment Compatibility

Pages and documents may require acknowledgment. This includes PDF and DOCX document records at the document-record level.

---

## 28. SVG and Diagram Handling Rules

### 28.1 Runtime Format Policy

The canonical runtime approach is:
- sanitized SVG preferred,
- raster fallback accepted,
- hotspot overlay independent from SVG internals.

### 28.2 Notation-Agnostic Rule

The product supports diagram styles such as:
- flowcharts,
- Ablaufdiagramme,
- swimlanes,
- BPMN-like diagrams,
- decision trees,
- check sequences.

It is not BPMN-exclusive.

### 28.3 Allowed Asset Types

Allowed visual upload formats for processes:
- SVG
- PNG
- JPG / JPEG
- WebP

SVG is strongly preferred where practical.

### 28.4 Sanitization Is Mandatory

Raw SVG is never trusted.

The sanitizer configuration must:
- strip scripts,
- strip event handler attributes,
- strip external references,
- strip unsafe embedded content,
- and allow only a defined safe subset.

### 28.5 Draw.io / diagrams.net Compatibility

Because draw.io / diagrams.net SVG exports commonly use `foreignObject` for wrapped text labels, the sanitizer configuration must not blindly strip all `foreignObject` content.

Instead, the safe policy is:
- allow `foreignObject` only within a tightly defined safe subset,
- sanitize allowed child HTML structure aggressively,
- allow only minimal inline styling needed for label rendering,
- reject scripts, event handlers, external resources, and unsafe namespaces.

The baseline goal is:
- **keep legitimate label text visible**
- without opening the door to arbitrary active content.

### 28.6 No Hidden Remote Dependencies

SVG must not rely on:
- remote fonts,
- remote CSS,
- remote JS,
- or remote images.

### 28.7 Source Adapter Rule

If future authoring conveniences such as Mermaid or PlantUML are ever added, they must be treated as **source adapters** or export pipelines only.

The runtime contract remains:
- rendered asset + hotspot overlay.

---

## 29. Viewer, Editor, and Front-End Architecture

### 29.1 Architecture Principle

Use server-rendered WordPress routes with focused React-powered interfaces where they actually add value.

### 29.2 Building View

The building view route is server-rendered with an embedded config payload that boots the handbook viewer app.

### 29.3 Process Editor

The process hotspot editor is an admin-side focused JS application.

### 29.4 Global Admin Shell

Reports, bundle editing, and other high-interaction admin screens may use a compact React admin shell.

### 29.5 No Router Overreach

Do not build a large front-end route system unless later complexity genuinely requires it.

### 29.6 Vendor Asset Strategy

Vendor assets like PDF.js must be packaged locally and loaded only where needed.

---

## 30. REST API

### 30.1 General Rules

- use `wp-json` custom namespace
- validate and sanitize every input
- check capability on every protected route
- return predictable success/error structures

### 30.2 Baseline Endpoints

The baseline endpoint set should include at least:

- `GET /buildings/by-token/{qr_token}`
- `GET /buildings/{id}/bundle`
- `GET /search/index`
- `POST /acknowledgments`
- `POST /processes/{id}/execute`
- `GET /processes/{id}/hotspots/{hotspot_id}/executions`
- `GET /buildings/{id}/observations`
- `POST /buildings/{id}/observations`
- `POST /observations/{id}/resolve`
- admin endpoints for managing building links and area metadata as needed

### 30.3 Execution Payload

Execution payload must include:
- `building_id`
- optional `area_key`
- `hotspot_id`
- `outcome`
- optional `note`
- `post_revision_id`
- optional `client_recorded_at`
- optional `queue_uuid`

### 30.4 Time Semantics

If `client_recorded_at` is present and valid:
- it becomes `executed_at`
- while `created_at` remains server insertion time

### 30.5 History Endpoint

The history endpoint for a hotspot must accept contextual filters such as:
- `building_id`
- optional `area_key`
- `limit`

This powers the field bottom-sheet recent-history display.

### 30.6 Error Shape

REST errors should follow a predictable structure such as:

```json
{
  "code": "orgahb_forbidden",
  "message": "You are not allowed to perform this action.",
  "data": {
    "status": 403
  }
}
```

Use normal WordPress REST conventions where possible.

---

## 31. Reports and Export

### 31.1 Reporting Philosophy

Reports support:
- governance,
- auditability,
- and handbook maintenance,

not operational dispatching.

### 31.2 Baseline Reports

The baseline report set should include:

1. acknowledgment export
2. field evidence export
3. observations export
4. building bundle mapping export
5. content inventory export
6. review-date export

### 31.3 Export Formats

Baseline export formats:
- CSV
- print-optimized HTML view

Optional later:
- client-generated PDF file using browser print or `pdf-lib` if justified

### 31.4 Backup Export

Backup export is a separate administrative export path intended for uninstall safety or migration support, not everyday reporting.

### 31.5 No Fake Ticket Dashboard

Reports must not evolve into:
- ticket backlogs,
- service-board dashboards,
- or SLA-control screens.

---

## 32. Accessibility Requirements

### 32.1 Minimum Accessibility Standard

The baseline UI must aim for practical WCAG-aligned behavior for internal enterprise tools.

### 32.2 Requirements

At minimum:
- keyboard-navigable controls,
- visible focus states,
- semantic buttons/links,
- accessible dialog / bottom-sheet behavior,
- adequate contrast,
- form labels,
- touch targets suitable for field use,
- understandable status text.

### 32.3 Diagram Accessibility

For process diagrams:
- hotspot labels must exist in text,
- and a text list fallback should exist where practical for accessibility and search.

---

## 33. Security Requirements

### 33.1 General Security Posture

This is an internal tool, but it still handles:
- controlled documents,
- staff identities,
- operational notes,
- and audit-relevant evidence.

So internal-only is not a reason to lower standards.

### 33.2 Authentication and Access

There is no unauthenticated public access in the baseline.

### 33.3 Capability Checks

Every mutating action must verify capability before performing work.

### 33.4 Input Handling

All inputs must be sanitized before persistence and escaped on output.

### 33.5 SVG Safety

SVG uploads must pass the sanitizer and any additional file-type checks before use.

### 33.6 File Handling

Controlled files must be accessed through WordPress-safe mechanisms rather than arbitrary filesystem exposure.

### 33.7 Deleted User / Privacy Safety

On user deletion:
- active identity pointers in custom tables are nulled or neutralized as configured,
- historical display labels are retained only as needed for audit readability,
- and data handling should align with privacy hooks.

### 33.8 Queue Deduplication

If an offline queue is enabled, `queue_uuid` must be used to prevent accidental duplicate submission.

---

## 34. Low-Connectivity and Offline Resilience

### 34.1 Product Position

The product is not “offline-first,” but it should remain practical under weak connectivity for core field interactions.

### 34.2 Baseline Rule

The baseline may ship as online-first.

### 34.3 Optional Queue

If enabled, a local IndexedDB queue may buffer:
- acknowledgments,
- evidence events,
- observations.

### 34.4 Revision Acceptance Rule

Late-arriving queued actions are accepted against the revision actually used by the operator, even if that revision was recently superseded.

### 34.5 Conflict Philosophy

For evidence and acknowledgment events, append-only logging makes conflicts much easier:
- duplicate prevention uses `queue_uuid`,
- historical truth is preserved,
- no in-place merge UI is required.

---

## 35. Performance and Caching

### 35.1 Baseline Performance Goal

The product should feel fast on normal intranet hardware and ordinary staff phones.

### 35.2 Cache Targets

Reasonable cache targets include:
- global search index payload,
- building bundle payloads,
- rendered building menus / area structures,
- report query intermediates where safe.

### 35.3 Invalidation Triggers

Invalidate relevant caches when:
- content is published / archived / restored,
- search-affecting metadata changes,
- building links change,
- building areas change,
- or building activity state changes.

### 35.4 Query Discipline

The building view should rely on the dedicated `wp_orgahb_building_links` relation table rather than expensive meta-query guessing.

---

## 36. Import, Bootstrap, and Backup Policy

### 36.1 Why Bootstrap Matters

A handbook tool lives or dies on how quickly real buildings can be modeled.

### 36.2 Baseline Manual Setup

The baseline must support a clean manual setup flow for:
- creating buildings,
- defining areas,
- linking content,
- and generating building QR labels.

### 36.3 Approved Immediate Import Paths

Approved near-term imports:
- building CSV import
- building-link CSV import
- bulk document import

### 36.4 Backup Versus Restore

Backup export is in scope.

A fully automated restore/import from backup ZIP is not required in the baseline.

### 36.5 External-System Coexistence

If another system already stores complaints or tickets:
- OrgaHB should coexist with it,
- not demand that the organization migrates everything into OrgaHB.

---

## 37. Explicit Non-Goals and Anti-Creep Rules

The following are explicit non-goals for the baseline and should be resisted during implementation:

1. one QR per process,
2. many mandatory QR stickers per building,
3. apartment-level modeling,
4. tenant master data,
5. ticket queues,
6. SLA dashboards,
7. contractor dispatch,
8. work-order lifecycle,
9. recurring due/overdue maintenance engine,
10. calendarized service planner,
11. invoice or vendor accounting,
12. full CRM behavior,
13. native BPMN editor,
14. AI chat assistant,
15. general document-management platform ambitions.

If a requirement makes the product feel like:
- a helpdesk,
- a property CRM,
- a CAFM tool,
- or a maintenance scheduler,

it should probably be deferred or rejected.

---

## 38. Canonical Decisions

This section restates the most important final decisions in plain language.

### 38.1 Organizational Handbook First
This is the primary identity of the product.

### 38.2 One QR Per Building
This is the baseline physical deployment model.

### 38.3 Building Bundle, Not Process Sticker
The QR opens a building-specific content bundle, not a single process.

### 38.4 Areas Are Internal Navigation
Areas such as stairwell, basement, heating room, outside area, and waste area exist as navigation inside the building view, not as separate baseline QR entities.

### 38.5 Evidence Yes, Task Engine No
The plugin may record evidence when a process step is used, but it does not become a due/overdue planner.

### 38.6 Observations Yes, CRM Ticketing No
The plugin may store lightweight building observations, but it does not become the main complaint or ticket system.

### 38.7 Common Areas Only
The plugin focuses on buildings and their shared/common operational contexts, not apartments.

### 38.8 SVG Yes, DOCX Conversion No
SVG remains a first-class diagram format. DOCX remains stored and versioned without HTML conversion.

### 38.9 Notation-Agnostic Visuals
Flowcharts, Ablaufdiagramme, BPMN-like diagrams, and similar visuals are all acceptable. The runtime stays visual + hotspot based.

### 38.10 WordPress-Native Lifecycle
Activation, deactivation, uninstall, privacy hooks, settings, readme, and backup behavior must follow WordPress expectations.

---

## 39. Definition of a Sound Plan

A sound plan for this product must satisfy all of the following:

1. a non-technical stakeholder can explain it in one sentence,
2. a building can be set up with one QR code,
3. staff can find the right building-specific content quickly,
4. the plugin remains clearly distinct from CRM/ticket systems,
5. WordPress deployment is normal and boring,
6. documents and acknowledgments are revision-safe,
7. diagrams remain usable on phones,
8. the data model is small enough for a solo builder to maintain,
9. exports prove handbook usage and evidence,
10. scope remains narrow enough to finish.

This specification is considered sound because it satisfies that narrower product boundary.

---

## 40. Immediate Coding Guardrails

Before writing major code, keep these guardrails visible:

1. Start with one full vertical slice:
   - create building
   - define areas
   - link content
   - generate QR
   - scan QR
   - open building bundle
   - open process/document
   - acknowledge or log evidence
   - export results

2. Do not implement a task engine “just because the data is almost there.”

3. Do not let observations turn into tickets.

4. Do not add apartment/unit models.

5. Do not add extra QR requirements without a hard, real-world justification.

6. Keep the first UI understandable without training:
   - “scan building”
   - “choose area”
   - “open content”
   - “leave proof or note if needed”

7. Write code so building-link relations, observations, and evidence remain composable, but do not pre-build the whole future roadmap.

---

## 41. Final Note

The correct simplification is not a reduction in product value.  
It is a sharpening of product identity.

**OrgaHB Manager succeeds when it feels like the right handbook for a real building in front of a real staff member.**

It fails when it starts pretending to be the organization’s entire property operations suite.
