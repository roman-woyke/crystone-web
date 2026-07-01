# Migrationsplan: PHP → Next.js

## Fortschritt

- [x] Phase 1 — Fundament
- [x] Phase 2 — Auth-Seiten + Dashboard
- [x] Phase 3 — Leaderboard + Avatar
- [x] Phase 4 — Study Counter (inkl. Focus Mode, nicht ursprünglich geplant)
- [ ] Phase 5 — Calendar + Projects
- [ ] Phase 6 — fWordle
- [ ] Phase 7 — Landing Page + Cleanup

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

## Phase 5 — Calendar + Projects

**Ziel:** Kalender und Projekt-Tracker funktionieren.

### 5.1 Calendar

- `POST /api/exams/toggle` — User-Exam-Verknüpfung toggeln
- `calendar/page.tsx` — Server Component liest Exams direkt per Prisma

### 5.2 Projects

- CRUD-Routes für Projects + Time Entries (analog zu Dashboard)
- Client-Timer in `localStorage` bleibt identisch, nur als React Hook:
  ```typescript
  const [startEpoch, setStartEpoch] = useLocalStorage(`project_timer_${id}`, null)
  ```
- Standings-Podium als React-Komponente

---

## Phase 6 — fWordle

**Ziel:** fWordle vollständig portiert. (Komplexeste Phase — zuletzt.)

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

## Phase 7 — Landing Page + Cleanup

- `app/page.tsx` — Landing Page (Server Component, direkte Prisma-Queries)
- Alle PHP-Dateien entfernen
- `CLAUDE.md` aktualisieren

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
