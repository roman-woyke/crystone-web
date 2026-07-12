# Local Setup (from scratch, any machine)

Step-by-step guide for getting this app running locally on a machine that has
never run it before. See [`CLAUDE.md`](CLAUDE.md) for architecture and
[`README.md`](README.md) for the authoritative database schema.

## Prerequisites

- **Docker Desktop** (or any Docker engine with `docker compose`).
  - macOS/Windows: install Docker Desktop and make sure it's actually
    *running* (whale icon in the menu bar/tray) before continuing — `docker
    compose` commands hang or fail with a cryptic error otherwise.
  - Linux: install `docker` + the `docker-compose-plugin`, and make sure your
    user can run `docker` without `sudo` (or prefix commands with `sudo`).
- **Node.js 20+** and npm — *only* for the manual path below; the Docker-only
  quick start needs no local Node at all.

## Quick start — everything in Docker (no local Node needed)

```sh
docker compose up -d
```

That single command starts the whole stack (see `docker-compose.yml`):

- **MySQL 8** on `localhost:3306` — on the very first boot (empty data
  volume) the app container applies the schema automatically via
  `prisma db push` (`docker/bootstrap-db.mjs`); a database that already has
  tables is never touched.
- **phpMyAdmin** on [http://localhost:8081](http://localhost:8081)
  (`root` / `rootpass`).
- **The Next.js dev server** on [http://localhost:3000](http://localhost:3000)
  — a plain `node:22` container with the repo bind-mounted; `npm install` +
  `npx prisma generate` run inside the container on start (dependencies live
  in a container-side volume, so the first boot takes a few minutes and
  later boots are fast). Follow along with `docker compose logs -f app`.
- **The socket.io realtime push server** on `localhost:3001`.

Secrets come from a repo-root `.env` if one exists (`AUTH_SECRET`,
`INVITE_CODE`, `REALTIME_INTERNAL_SECRET`); without one, dev-only defaults
apply (invite code `letmein`) — fine for localhost, never for a deployment.
Register your first account at
[http://localhost:3000/register](http://localhost:3000/register) with that
invite code. Stop everything with `docker compose down` (data survives in
the `db_data` volume).

Note: the app and realtime containers publish ports 3000/3001, so don't run
`npm run dev` / `npm run realtime` on the host at the same time.

---

The rest of this guide is the **manual path** (host-side Node, only the
database in Docker) — useful if you prefer running Next.js directly.

## 1. Install dependencies

```sh
npm install
```

## 2. Start local MySQL

```sh
docker compose up -d
```

This starts (see `docker-compose.yml`):
- MySQL 8 on `localhost:3306` (root password `rootpass`, database `local_site_db`)
- phpMyAdmin on [http://localhost:8081](http://localhost:8081) (login: `root` / `rootpass`) — handy for poking at data directly.

Check both containers are up with `docker compose ps`.

## 3. Configure `.env`

```sh
cp .env.example .env
```

Then edit `.env`:
- `DATABASE_URL` — point it at the local Docker MySQL instead of the
  Hostinger placeholder:
  ```
  DATABASE_URL="mysql://root:rootpass@localhost:3306/local_site_db"
  ```
- `AUTH_SECRET` — generate one with `npx auth secret` or `openssl rand -base64 32`.
- `INVITE_CODE` — pick any string (e.g. `dev-invite`); this is the code
  you'll enter on the registration page to create your first local account.

## 4. Apply the database schema

The local database starts empty — Docker only creates the schema-less
`local_site_db` database, not the tables. Use Prisma to create them directly
from `prisma/schema.prisma`, which is the actual live shape of the app
(`README.md`'s documented `CREATE TABLE` statements are known to lag behind
in places — e.g. `application_status_history.user_id` isn't in the README
but is required by the app code):

```sh
npx prisma db push
```

This is also the fix if you ever hit a `PrismaClientKnownRequestError: The
column ... does not exist in the current database` error — it means the
local DB drifted from `prisma/schema.prisma`; re-run `db push` to sync it.

## 5. Generate the Prisma client

```sh
npx prisma generate
```

Re-run this any time `prisma/schema.prisma` changes.

## 6. Run the dev server

```sh
npm run dev
```

And in a second terminal, the realtime push server (optional — everything
works without it, updates are just poll-free only when it runs):

```sh
npm run realtime
```

Open [http://localhost:3000](http://localhost:3000) — you should be redirected
to `/login`. Register a new account using the `INVITE_CODE` you set in `.env`.

If you use the manual path, either stop the dockerized `app`/`realtime`
services first (`docker compose stop app realtime`) or they'll already be
holding ports 3000/3001.

## Everyday use (after the first setup)

Docker-only workflow: `docker compose up -d`, that's it.

Manual workflow:

```sh
docker compose up -d db phpmyadmin   # just the database side
npm run dev
npm run realtime                     # second terminal, optional
```

Data persists across restarts in the `db_data` Docker volume — you only need
to redo step 4 (schema) if you tear that volume down (`docker compose down -v`).

## Troubleshooting

- **`docker compose` hangs or errors with a connection/daemon error** — Docker
  Desktop isn't running. Start it and wait for the whale icon to settle
  before retrying.
- **Port already in use (3306, 8081, or 3000)** — something else on the
  machine is already bound to that port. Either stop it, or change the port
  mapping in `docker-compose.yml` (left side of `"host:container"`) and
  update `DATABASE_URL`/the URL you visit accordingly.
- **Prisma errors about tables/columns not existing** (e.g. `The column
  ... does not exist in the current database`) — the schema (step 4) wasn't
  applied yet, was applied to the wrong database name, or drifted from
  `prisma/schema.prisma`. Re-run `npx prisma db push`.
- **Can't register / "invalid invite code"** — make sure `INVITE_CODE` in
  `.env` matches exactly what you type on the registration page, and that you
  restarted `npm run dev` after editing `.env`.
