# Migrationsplan: PHP → Next.js

## Fortschritt

- [x] Phase 1 — Fundament
- [x] Phase 2 — Auth-Seiten + Dashboard
- [x] Phase 3 — Leaderboard + Avatar
- [x] Phase 4 — Study Counter (inkl. Focus Mode, nicht ursprünglich geplant)
- [x] Phase 5 — Calendar + Projects
- [x] Phase 6 — fWordle
- [x] Phase 7 — Landing Page + Cleanup

Abweichungen vom Plan siehe Notizen in den jeweiligen Phasen unten.

## Stack

| Bereich | Technologie |
|---|---|
| Framework | Next.js 16 (App Router) — Plan sah 15 vor, 16 war zum Umsetzungszeitpunkt aktuell |
| Sprache | TypeScript |
| Styling | Tailwind CSS v4 + shadcn/ui |
| Datenbank | MySQL (bleibt) via Prisma 7 ORM (`@prisma/adapter-mariadb`) |
| Auth | Auth.js v5 (Credentials Provider) |
| Data Fetching | Eigener `useState`/`useEffect`-Polling-Code statt SWR (siehe Phase 4) |
| Deployment | Vercel + Hostinger Remote MySQL (noch nicht eingerichtet) |

**Warum dieser Stack:**
- Prisma versteht das bestehende MySQL-Schema 1:1, kein Datenverlust
- Auth.js Credentials Provider kann PHP-Passwort-Hashes (bcrypt) direkt verifizieren — bestehende User-Accounts funktionieren sofort
- shadcn/ui gibt fertige, schöne Komponenten (Dialogs, Cards, Tabs etc.) ohne Design-Arbeit
- Vercel deployt automatisch bei jedem Push auf `main`

---

## Neue Ordnerstruktur

```
/
├── app/
│   ├── (auth)/
│   │   ├── login/page.tsx
│   │   └── register/page.tsx
│   ├── (app)/
│   │   ├── layout.tsx          ← Auth-Guard + globales Layout (Header/Footer)
│   │   ├── dashboard/page.tsx
│   │   ├── leaderboard/page.tsx
│   │   ├── study/page.tsx
│   │   ├── calendar/page.tsx
│   │   ├── projects/page.tsx
│   │   └── fwordle/page.tsx
│   ├── api/
│   │   ├── auth/[...nextauth]/route.ts
│   │   ├── applications/route.ts        (GET, POST)
│   │   ├── applications/[id]/route.ts   (PATCH, DELETE)
│   │   ├── leaderboard/route.ts
│   │   ├── score-history/route.ts
│   │   ├── raw-events/route.ts
│   │   ├── study/sessions/route.ts
│   │   ├── study/status/route.ts
│   │   ├── study/timer/route.ts
│   │   ├── study/data/route.ts
│   │   ├── projects/route.ts
│   │   ├── projects/[id]/route.ts
│   │   ├── time-entries/[id]/route.ts
│   │   ├── exams/toggle/route.ts
│   │   ├── avatar/route.ts
│   │   ├── fwordle/state/route.ts
│   │   ├── fwordle/guess/route.ts
│   │   ├── fwordle/choose/route.ts
│   │   └── fwordle/hint/route.ts
│   └── page.tsx                ← Landing Page (index.php)
├── components/
│   ├── ui/                     ← shadcn/ui Komponenten (auto-generiert)
│   ├── layout/
│   │   ├── Header.tsx
│   │   └── Footer.tsx
│   ├── dashboard/
│   ├── leaderboard/
│   ├── study/
│   ├── projects/
│   └── fwordle/
├── lib/
│   ├── prisma.ts               ← Prisma Client Singleton
│   ├── auth.ts                 ← Auth.js Konfiguration
│   ├── scoring.ts              ← scorePoints() in TypeScript
│   └── fwordle.ts              ← fWordle Logik portiert
├── prisma/
│   └── schema.prisma           ← Alle Tabellen aus README.md
├── public/
│   └── (Favicons, Icons)
└── includes/fwordle/           ← Wortlisten bleiben als .txt Dateien
```

---

## Phase 1 — Fundament (ohne Features) ✅ erledigt

**Ziel:** Leeres Next.js-Projekt läuft lokal und auf Vercel, DB ist verbunden.

**Notizen:**
- Next.js 16 statt 15 (aktueller Stand zum Zeitpunkt der Umsetzung), Prisma 7 statt der impliziten älteren Version.
- Prisma 7 verlangt für MySQL einen Driver-Adapter statt reiner Connection-URL → `@prisma/adapter-mariadb` installiert, inkl. `allowPublicKeyRetrieval: true` (nötig für MySQL 8 `caching_sha2_password` ohne TLS).
- `prisma db pull` gegen eine lokale Docker-Test-DB (README-Schema) zeigte: `TIMESTAMP DEFAULT CURRENT_TIMESTAMP`-Spalten sind ohne `NOT NULL` nullable, `TINYINT` ohne `(1)` mappt auf `Int` statt `Boolean` — Schema entsprechend korrigiert.
- Vercel/Hostinger-Verbindung (1.5) nicht umgesetzt — das sind Konten-Aktionen außerhalb meines Zugriffs; `.env.example` mit den drei nötigen Variablen vorbereitet.

### 1.1 Projekt anlegen

```bash
npx create-next-app@latest internship-leaderboard-next \
  --typescript --tailwind --app --src-dir=false --eslint
cd internship-leaderboard-next
npx shadcn@latest init
```

### 1.2 Dependencies

```bash
npm install prisma @prisma/client
npm install next-auth@beta
npm install swr
npm install bcryptjs && npm install -D @types/bcryptjs
npx prisma init
```

### 1.3 Prisma Schema

Alle 14 Tabellen aus `README.md` in `prisma/schema.prisma` übersetzen.
MySQL ENUMs (PENDING, INTERVIEW etc.) werden zu Prisma `enum`-Typen.
Danach:
```bash
npx prisma db pull   # Schema aus bestehender DB einlesen (Abgleich)
npx prisma generate
```

### 1.4 Auth.js einrichten

- Credentials Provider: `username` + `password`
- `authorize()` holt User per Prisma, verifiziert mit `bcryptjs.compare()` (kompatibel mit PHP `password_hash`)
- Session-Strategie: JWT (kein Datenbank-Session-Table nötig)
- Bestehende Passwörter funktionieren ohne Reset

### 1.5 Vercel + DB verbinden

- Vercel Projekt anlegen, GitHub Repo verbinden
- In Hostinger hPanel: Remote MySQL-Zugriff aktivieren
- In Vercel Environment Variables:
  - `DATABASE_URL=mysql://user:pass@hostinger-host/db`
  - `AUTH_SECRET=...`
  - `INVITE_CODE=...`

---

## Phase 2 — Auth-Seiten + Dashboard (MVP) ✅ erledigt

**Ziel:** Einloggen, Bewerbungen sehen/bearbeiten funktioniert.

**Notizen:**
- `application_status_history` hat eine `user_id`-Spalte, die im README fehlt, aber vom PHP-Code vorausgesetzt wird — im Schema ergänzt.
- Komponenten heißen `ApplicationsTable.tsx` (Tabelle mit Inline-Editing) statt `ApplicationCard.tsx`, da das Original eine Tabelle mit Status/Tag-Dropdowns, Peak-Status-Indikator und Link/Notes-Edit-Modal ist, keine Card-Grid-Ansicht.
- bcryptjs-Kompatibilität mit PHPs `$2y$`-Hash-Präfix explizit getestet (funktioniert).
- `api/patch-profile.php` (Username/Passwort im Profil ändern) bewusst nicht portiert — steht weder im Plan noch in der CLAUDE.md-API-Tabelle.

### 2.1 Login & Register

- `app/(auth)/login/page.tsx` — Form → `signIn()` von Auth.js
- `app/(auth)/register/page.tsx` — POST an `api/auth/register/route.ts`, prüft `INVITE_CODE`, hasht mit bcryptjs, legt User an

### 2.2 Auth-Guard Layout

- `app/(app)/layout.tsx` — prüft Session via `auth()`, leitet zu `/login` weiter wenn nicht eingeloggt

### 2.3 Dashboard

**API Routes:**
- `GET /api/applications` — alle Bewerbungen des eingeloggten Users
- `POST /api/applications` — neue Bewerbung anlegen (schreibt History-Row)
- `PATCH /api/applications/[id]` — Status/Tag/Notes ändern (schreibt History-Row)
- `DELETE /api/applications/[id]` — Bewerbung löschen (CASCADE auf History)

**Alle Routes:** `session.user.id` als Ownership-Check, `AND user_id = ?` Äquivalent in Prisma: `.findFirst({ where: { id, userId } })`

**Komponenten:**
- `ApplicationCard.tsx` — eine Bewerbungs-Karte
- `AddApplicationDialog.tsx` — shadcn `Dialog` + Form
- `StatusBadge.tsx` — farbige Status-Anzeige

### 2.4 Scoring-Logik portieren

`lib/scoring.ts`:
```typescript
export function scorePoints(status: Status, tag: string | null): number { ... }
```
1:1 aus `includes/scoring.php` übernehmen.

---

## Phase 3 — Leaderboard + Avatar ✅ erledigt

**Ziel:** Leaderboard mit Chart funktioniert.

### 3.1 Leaderboard

- `GET /api/leaderboard` — alle User mit Bewerbungen + berechneten Scores
- `GET /api/score-history` — tägliche kumulative Scores (für Chart)
- `GET /api/raw-events` — Rohdaten für Tooltips

**Chart:** Chart.js direkt (nicht Recharts/Tremor) — die Original-Logik (Gradient-Strokes, gebündelte Sub-Punkte für gleichtägige Events, day-boundary-Achse) ist bereits auf Chart.js aufgebaut; 1:1-Port war weniger Aufwand als ein Rewrite.

**Wichtiger Fund:** `@prisma/adapter-mariadb` kodiert DB-Timestamps so, dass die rohen gespeicherten Ziffern 1:1 als UTC gelesen werden (keine echte TZ-Konvertierung) — `lib/dates.ts` (`dbNow()`, `toDbDateStr()`) kodiert "jetzt" auf dieselbe Art, damit Tagesgrenzen-Berechnungen konsistent mit den Europe/Berlin-Wanduhrzeiten der PHP-App bleiben, unabhängig von der Serverzeitzone (z. B. Vercel/UTC). Kein `SET time_zone=...` auf der Verbindung, da das auf Shared-Hosting ohne geladene IANA-Zeitzonentabellen fehlschlagen kann.

### 3.2 Avatar Upload

- `POST /api/avatar` — validiert base64 Data URL, speichert in `users.avatar`
- `DELETE /api/avatar` — löscht Avatar (`remove=1` Äquivalent)
- Client-side Canvas-Logik (crop + resize auf 256px) bleibt identisch, nur als React-Hook verpackt

---

## Phase 4 — Study Counter ✅ erledigt

**Ziel:** Timer, Presence-Dock, manuelle Sessions, Charts funktionieren.

### 4.1 API Routes

- `GET /api/study/status` — aktueller Zustand (wer lernt, mein Timer)
- `POST /api/study/timer` — State Machine (start/pause/resume/set_module/stop/log/library)
- `GET /api/study/data` — Module + aggregierte Sessions
- `POST /api/study/sessions` — manuelle Session loggen
- `DELETE /api/study/sessions/[id]` — Session löschen
- `GET /api/study/my-sessions` — eigene Sessions für Manage-Fenster
- `POST /api/study/delete-module` — Custom-Modul löschen (undokumentiert im Original, aber von der UI benötigt)

### 4.2 Polling

Kein SWR — stattdessen eigener `useState`/`useEffect`-Orchestrator (`StudyCounterApp.tsx`) mit `setInterval(15s)`, `visibilitychange` und `pageshow` (bfcache), analog zum Original. SWR wurde nicht gebraucht, da der State (Timer, Presence, Recap) zu eng verzahnt ist für einfache Key-basierte Revalidierung.

### 4.3 Komponenten

Tatsächliche Struktur: `StudyCounterApp.tsx` (Orchestrator), `Dock.tsx` + `Timeline.tsx`, `Chart.tsx`, `Podium.tsx`, `ModulePicker.tsx` + `ModuleModal.tsx`, `StopModal.tsx`, `ManualLogModal.tsx`, `ManageSessionsModal.tsx`, `FocusMode.tsx`, `Tooltip.tsx`.

**Wichtig:** Server-Rendering der initialen Daten (`INITIAL_STATUS`) wurde wie geplant durch Next.js Server Components + `async/await` in `app/(app)/study/page.tsx` ersetzt — kein Loading-Flash.

**Zusatzfeature (nicht im Plan):** Focus Mode (Vollbild-Timer, 3 Wochen-Mini-Charts, persönliche Gesamtzeit) wurde auf Nutzerwunsch mit portiert.

**Bewusste Vereinfachungen** (funktional vollständig, aber nicht pixelgenau zum Original):
- Timeline-Achse: feste 07:00–06:00-Achse statt der Original-Optimierung, die leere Stunden zu einem "⋯"-Marker komprimiert.
- Flip-Dock: einfacher Wechsel statt 3D-Y-Achsen-Rotation mit zwei Animationsphasen.
- Focus Mode: Vollbild-Overlay statt eingebettetem Layout mit separat ein-/ausklappbarer Sidebar/Dock.

**Kritischer, phasenübergreifender Fund:** Felder, die nicht explizit mit `dbNow()` gesetzt wurden, wurden von MySQLs eigenem `NOW()`/`ON UPDATE CURRENT_TIMESTAMP`-Trigger befüllt — das lief in der DB-Session-Zeitzone (lokal: UTC), nicht in `dbNow()`s Europe/Berlin, und verursachte reale 2h-Versätze (`break_elapsed` sprang auf ~7200s). Fix rückwirkend auch in Phase 2/3 angewendet: jeder Schreibzugriff auf `study_status`, `study_sessions`, `study_segments`, `applications`, `application_status_history`, `users.created_at` setzt Zeitstempel jetzt explizit über `dbNow()`.

---

## Phase 5 — Calendar + Projects ✅ erledigt

**Ziel:** Kalender und Projekt-Tracker funktionieren.

**Notizen:**
- Tatsächliche API-Struktur weicht leicht vom Plan ab: Der Plan sah nur `projects/route.ts`, `projects/[id]/route.ts` und `time-entries/[id]/route.ts` vor, ohne eine Create-Route für Zeiteinträge zu nennen — ergänzt um `POST /api/time-entries/route.ts` (analog zu `POST /api/applications`).
- `ProjectTimeEntry` hat in Prisma bereits `onDelete: Cascade` auf `Project` — anders als im PHP-Original (`delete-project.php` löscht Time Entries manuell zuerst) reicht ein einzelnes `prisma.project.deleteMany()`.
- Kalender-Feature nutzt bewusst weiterhin die feste `["Roman", "Basti", "Ben", "Lorenz"]`-Liste (`lib/calendar.ts`) statt der `users`-Tabelle, identisch zum PHP-Original (`user_exams` ist über `username`-String verknüpft, nicht `user_id`) — Projects nutzt dagegen echte User-Records aus der DB, genau wie im Original.
- `examCountdown()` aus Phase 4 (`lib/exam-countdown.ts`) wurde direkt wiederverwendet statt neu geschrieben — war schon exakt auf dieses Feature zugeschnitten.
- Kein pixelgenauer Port des PHP-Designs (Glow-Karten, eigene CSS-Variablen) — wie in Phase 2–4 durchgängig auf shadcn/ui + Tailwind umgestellt.
- Farb-Inkonsistenz aus dem Original bereinigt: `projects.php` (Erstellung) und `patch-project.php` (Validierung) hatten zwei unterschiedliche 8er-Paletten. Für den Port gilt einheitlich die Palette aus `projects.php` (`lib/project-colors.ts`), für Erstellung **und** Validierung.

### 5.1 Calendar

- `POST /api/exams/toggle` — User-Exam-Verknüpfung toggeln
- `calendar/page.tsx` — Server Component liest Exams direkt per Prisma

### 5.2 Projects

- CRUD-Routes für Projects + Time Entries (analog zu Dashboard)
- Client-Timer in `localStorage` bleibt identisch, nur als React Hook (kein `useLocalStorage`-Hook nötig — `ProjectCard.tsx` liest/schreibt direkt in `useEffect`/Handlern, analog zum ursprünglichen inline-JS)
- Standings-Podium als React-Komponente (`components/projects/Standings.tsx`)

---

## Phase 6 — fWordle ✅ erledigt

**Ziel:** fWordle vollständig portiert. (Komplexeste Phase — zuletzt.)

**Notizen:**
- `fwordle_words.hint` / `fwordle_choices.hint` sind wie `application_status_history.user_id` (Phase 2) nicht im README dokumentiert, aber vom PHP-Code gelesen/geschrieben (der Wortpicker-Hinweistext) — in Prisma-Schema + README ergänzt, inkl. `ALTER TABLE`-Migrationshinweis analog zum `via_freeze`-Eintrag.
- `lib/fwordle.ts` ist bewusst in vier Dateien aufgeteilt (`fwordle-words.ts`, `fwordle-score.ts`, `fwordle-streak.ts`, `fwordle.ts` als Orchestrator), analog zur Aufteilung von Phase 4 (`study-status.ts`, `study-segments.ts`, `study-modules.ts`, …) statt einer 750-Zeilen-Datei.
- `fwordleFinalizeWords()` nutzt `prisma.$transaction` mit `SELECT ... FOR UPDATE` (Raw Query) für dieselbe Race-Sicherheit wie das PHP-Original — als erste Stelle im Next-Port, die eine interaktive Prisma-Transaktion braucht.
- API-Routes nehmen JSON-Bodies (`request.json()`) statt der Original-`FormData`/`$_POST`-Bodies entgegen, konsistent zu allen anderen Next-Routes (z. B. `study/timer`) statt eine Sonderform beizubehalten.
- Kein SWR: Polling läuft wie beim Study Counter über `setInterval(6000)` + `visibilitychange` + `pageshow`, aus demselben in Phase 4 notierten Grund (der State ist ein einziger eng verzahnter Blob, für den ein einfacher Fetch-Loop weniger Overhead ist als SWR-Keys). Weicht vom ursprünglichen Plan-Eintrag "Polling alle 6s via SWR" ab.
- UI ist wie in Phase 2–5 auf shadcn/ui + Tailwind umgestellt statt das Original-Neon/Glow-CSS zu portieren. Zwei bewusste kosmetische Vereinfachungen: Wortpicker-Hinweiskarten und Joker-Reveal-Zeilen rendern in natürlicher Reihenfolge statt exakt unter ihrer Board-Spalte einjustiert zu sein (jede Karte trägt weiterhin ihr "Board N"-Label, funktional identisch).
- Joker-Kaufbestätigung nutzt weiterhin das native `confirm()`, konsistent mit bestehenden Stellen im Next-Port (z. B. Modul löschen im Study Counter).
- Getestet: lokale Docker-DB hochgefahren, `hint`-Migration angewendet, End-to-End über die API verifiziert (State-Abruf inkl. Tages-/Board-Erzeugung, Guess-Submission inkl. Scoring + Board-Freeze bei Sieg, Joker-Kauf inkl. Free-Joker-Logik, sowie dass Gegner nur Farben und nie Buchstaben sehen).

### 6.1 Logik portieren

`lib/fwordle.ts`:
- `fwordleScore()` — Guess-Scoring
- `fwordleRollLength()` — Tages-Länge bestimmen
- `fwordleFinalizeWords()` — Antworten festlegen
- `fwordleStreakInfo()` — Streak + Freezes berechnen
- `fwordleState()` — vollständiger State für einen User
- `fwordleIsValidWord()` — Wortlisten lazy laden (fs.readFileSync in Route Handler)

Wortlisten (`includes/fwordle/*.txt`) können direkt bleiben, werden per `fs` gelesen.

### 6.2 API Routes

- `GET /api/fwordle/state`
- `POST /api/fwordle/guess`
- `POST /api/fwordle/choose`
- `POST /api/fwordle/hint`

### 6.3 UI

- `FwordleBoard.tsx` — Board-Grid mit Zellen-Animationen
- `FwordleKeyboard.tsx` — On-Screen Keyboard mit Board-Switcher
- `JokerBar.tsx` — Joker-Buttons + Streak/Freeze Wallets
- Polling alle 6s via SWR

---

## Phase 7 — Landing Page + Cleanup ✅ erledigt

- [x] `app/page.tsx` — Landing Page
- [x] Alle PHP-Dateien entfernen
- [x] `CLAUDE.md` aktualisieren

**Notizen:**
- **Abweichung von CLAUDE.md:** `index.php` wurde entgegen der (offenbar veralteten/nie umgesetzten) CLAUDE.md-Beschreibung einer "Marketing-Landingpage mit Live-Stats" seit dem allerersten Commit tatsächlich immer nur als reiner Redirect implementiert (`isset($_SESSION["user_id"]) ? study-counter.php : login.php`). Auf Nutzerentscheidung hin 1:1 als reiner Redirect portiert (`app/page.tsx`: eingeloggt → `/study`, ausgeloggt → `/login`), keine neue Marketing-Seite gebaut.
- Echte Favicons/`site.webmanifest` (bisher nur die create-next-app-Platzhalter-SVGs in `public/`) nach `internship-leaderboard-next/public/` übernommen und über `app/layout.tsx`s `metadata.icons`/`metadata.manifest` verlinkt; `app/favicon.ico` durch das echte Favicon ersetzt.
- **PHP-Löschung ursprünglich zurückgestellt, dann nachgeholt:** Der Auto-Mode-Klassifiker hatte das Massen-`git rm` der PHP-App zunächst als irreversible Aktion in einem geteilten, bei jedem Push live deployenden Repo blockiert; der Nutzer wählte damals "erstmal nicht". Bei erneuter Anfrage stellte sich heraus, dass die Löschung auf einem isolierten Feature-Branch (`feat/nextjs-migration`) passiert, der nicht der geteilte Auto-Deploy-Branch ist und dessen Next.js-Teil ohnehin noch nicht über Vercel live ist — die PHP-App war hier ausschließlich als Referenz für den Next.js-Port vorhanden. Damit war das Risiko eines Produktions-Ausfalls nicht gegeben, und die Löschung wurde durchgeführt: `api/`, `assets/`, `includes/`, alle `*.php`, `htaccess`, sowie der PHP-Webservice aus `docker-compose.yml`/`docker/` entfernt (letzteres durch eine reine MySQL/phpMyAdmin-`docker-compose.yml` in `internship-leaderboard-next/` ersetzt, da die lokale DB weiterhin für Prisma-Entwicklung gebraucht wird).
- **Doku-Umbau:** Root-`CLAUDE.md` (vormals PHP-bezogen) wurde durch die vollständige Next.js-Architektur-/API-/Feature-Doku ersetzt, übersetzt auf die tatsächlichen Pfade (`app/api/.../route.ts`, `lib/*.ts`, `components/**/*.tsx`). `README.md` behält das DB-Schema als weiterhin autoritative Quelle für `prisma/schema.prisma`, der PHP-Deployment- und Dateistruktur-Teil wurde entfernt.
- **Nachträgliches Flachziehen der Ordnerstruktur:** Direkt im Anschluss an die PHP-Löschung wurde auch `internship-leaderboard-next/` selbst aufgelöst — alle App-Dateien eine Ebene hoch an die Repo-Wurzel verschoben (`git mv`), `node_modules`/`.next`/`tsconfig.tsbuildinfo` gelöscht statt verschoben (regenerieren sich via `npm install`/`npm run dev`), die verschachtelte `CLAUDE.md` in die Root-`CLAUDE.md` gemergt, `internship-leaderboard-next/README.md` (unveränderte create-next-app-Boilerplate) verworfen. Grund: die Verschachtelung existierte nur, damit PHP-App und Next.js-Port während der Migration nebeneinander im selben Repo liegen konnten — mit der PHP-Löschung entfiel der Grund dafür. `.gitignore`s `.env*`-Regel bekam dabei eine `!.env.example`-Ausnahme, da diese sonst nie im Git-Verlauf sichtbar war. Alle Pfadangaben in diesem Dokument, die noch `internship-leaderboard-next/` zeigen, sind historisch (Stand zum jeweiligen Phasen-Zeitpunkt) und nicht mehr aktuell.
- **Nach wie vor offen (nicht Teil von Phase 7):** Vercel + Hostinger-Remote-MySQL-Einrichtung (Plan-Punkt 1.5) — es gibt aktuell keine Live-Deployment-Pipeline für die Next.js-App.

---

## Tricky Punkte (nicht vergessen)

| Problem | Lösung |
|---|---|
| PHP bcrypt-Hashes | `bcryptjs.compare()` ist kompatibel — kein Passwort-Reset nötig |
| BASE_PATH | Entfällt komplett — Next.js Routing übernimmt das |
| `config.php` außerhalb Web-Root | Wird zu Vercel Environment Variables |
| fWordle Wortlisten (.txt) | Per `fs.readFileSync` in Route Handlers lesen |
| Avatar als base64 MEDIUMTEXT | Prisma `@db.MediumText` — bleibt identisch |
| Scoring `peakStatusSql()` | In TypeScript als JS-Funktion über History-Array statt Raw SQL |
| `session_start` Fallback (created_at - seconds) | In TypeScript-Helper kapseln |

---

## Reihenfolge auf einen Blick

```
Phase 1 → Fundament + DB-Verbindung + Vercel-Deploy
Phase 2 → Login / Dashboard (MVP, nutzbar)
Phase 3 → Leaderboard + Avatare
Phase 4 → Study Counter
Phase 5 → Calendar + Projects
Phase 6 → fWordle
Phase 7 → Landing Page + PHP löschen
```

Jede Phase ist einzeln deploybar — die alte PHP-App kann parallel auf dem Server bleiben bis Phase 7 abgeschlossen ist.
