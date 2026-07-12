// First-boot schema bootstrap for the dockerized dev stack: if the target
// database has no tables yet (fresh clone, empty db_data volume), create
// them with `prisma db push` — the same path SETUP.md prescribes for manual
// local setup, so the schema always matches what the app code actually
// expects (including columns the README's documented SQL has historically
// lagged on, like application_status_history.user_id). A database that
// already has tables is left completely untouched.

import { execSync } from "node:child_process";
import mariadb from "mariadb";

const url = new URL(process.env.DATABASE_URL);
const database = url.pathname.slice(1);

const conn = await mariadb.createConnection({
  host: url.hostname,
  port: url.port ? Number(url.port) : 3306,
  user: decodeURIComponent(url.username),
  password: decodeURIComponent(url.password),
  // MySQL 8's caching_sha2_password needs the server's RSA key on the very
  // first (uncached) auth of a user — acceptable for a local dev database.
  allowPublicKeyRetrieval: true,
});

const rows = await conn.query(
  "SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema = ?",
  [database],
);
await conn.end();

if (Number(rows[0].n) === 0) {
  console.log(`database "${database}" is empty — applying schema via prisma db push`);
  execSync("npx prisma db push", { stdio: "inherit" });
} else {
  console.log(`database "${database}" already has tables — skipping schema bootstrap`);
}
