# Migrationsplan: PHP → Next.js

## Stack

| Bereich | Technologie |
|---|---|
| Framework | Next.js 15 (App Router) |
| Sprache | TypeScript |
| Styling | Tailwind CSS v4 + shadcn/ui |
| Datenbank | MySQL (bleibt) via Prisma ORM |
| Auth | Auth.js v5 (Credentials Provider) |
| Data Fetching | SWR (Polling, Mutations) |
| Deployment | Vercel + Hostinger Remote MySQL |

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

## Phase 1 — Fundament (ohne Features)

**Ziel:** Leeres Next.js-Projekt läuft lokal und auf Vercel, DB ist verbunden.

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

## Phase 2 — Auth-Seiten + Dashboard (MVP)

**Ziel:** Einloggen, Bewerbungen sehen/bearbeiten funktioniert.

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

## Phase 3 — Leaderboard + Avatar

**Ziel:** Leaderboard mit Chart funktioniert.

### 3.1 Leaderboard

- `GET /api/leaderboard` — alle User mit Bewerbungen + berechneten Scores
- `GET /api/score-history` — tägliche kumulative Scores (für Chart)
- `GET /api/raw-events` — Rohdaten für Tooltips

**Chart:** Bestehende Chart-Logik aus `score-chart.php` in eine React-Komponente portieren. Alternativ: [Recharts](https://recharts.org) oder [Tremor](https://tremor.so) für weniger Aufwand.

### 3.2 Avatar Upload

- `POST /api/avatar` — validiert base64 Data URL, speichert in `users.avatar`
- `DELETE /api/avatar` — löscht Avatar (`remove=1` Äquivalent)
- Client-side Canvas-Logik (crop + resize auf 256px) bleibt identisch, nur als React-Hook verpackt

---

## Phase 4 — Study Counter

**Ziel:** Timer, Presence-Dock, manuelle Sessions, Charts funktionieren.

### 4.1 API Routes

- `GET /api/study/status` — aktueller Zustand (wer lernt, mein Timer)
- `POST /api/study/timer` — State Machine (start/pause/resume/set_module/stop/log/library)
- `GET /api/study/data` — Module + aggregierte Sessions
- `POST /api/study/sessions` — manuelle Session loggen
- `DELETE /api/study/sessions/[id]` — Session löschen
- `GET /api/study/my-sessions` — eigene Sessions für Manage-Fenster

### 4.2 Polling mit SWR

```typescript
const { data: status } = useSWR('/api/study/status', fetcher, {
  refreshInterval: 15_000,
})
```

Visibility-Change und bfcache pageshow via `useSWR`'s `revalidateOnFocus` + manuellem `mutate()`.

### 4.3 Komponenten

- `StudyDock.tsx` — Sticky Sidebar (Flip-Card: Front = live, Back = Tagesübersicht)
- `StudyChart.tsx` — Grouped Bar Chart (kann mit shadcn Charts / Recharts neu gebaut werden)
- `StudyPodium.tsx` — Podium mit Avataren
- `ModuleModal.tsx` — Modul-Auswahl beim Start
- `LogModal.tsx` — Trim/Drop per Modul beim Stoppen

**Wichtig:** Server-Rendering der initialen Daten (`INITIAL_STATUS`) wird durch
Next.js Server Components + `async/await` in der Page-Komponente ersetzt — kein Loading-Flash.

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
