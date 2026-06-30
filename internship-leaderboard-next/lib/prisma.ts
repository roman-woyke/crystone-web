import { PrismaMariaDb } from "@prisma/adapter-mariadb";

import { PrismaClient } from "@/app/generated/prisma/client";

const globalForPrisma = globalThis as unknown as {
  prisma: PrismaClient | undefined;
};

// The `mariadb` driver only parses its own `mariadb://` connection string
// scheme, so DATABASE_URL (kept as the standard `mysql://` format) is parsed
// into a pool config instead of being passed through as a string.
const url = new URL(process.env.DATABASE_URL as string);
const adapter = new PrismaMariaDb({
  host: url.hostname,
  port: url.port ? Number(url.port) : 3306,
  user: decodeURIComponent(url.username),
  password: decodeURIComponent(url.password),
  database: url.pathname.replace(/^\//, ""),
  // No explicit `timezone` here — that would issue `SET time_zone=...` on
  // every connection, which throws if the server's IANA zone tables aren't
  // loaded (common on shared MySQL hosts). Leaving it unset matches the PHP
  // app's plain PDO connection, which never sets one either. See lib/dates.ts
  // for how DB timestamps (Europe/Berlin wall-clock, no TZ info) are read.
});

export const prisma = globalForPrisma.prisma ?? new PrismaClient({ adapter });

if (process.env.NODE_ENV !== "production") {
  globalForPrisma.prisma = prisma;
}
