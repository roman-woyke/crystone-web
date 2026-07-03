import { ApplicationStatus } from "@/app/generated/prisma/enums";

const BASE_WEIGHTS: Partial<Record<ApplicationStatus, number>> = {
  PENDING: 2,
  REJECTED: -1,
  GHOSTED: -1,
};

// -2 because PENDING points are already counted.
const TAG_WEIGHTS: Record<"INTERVIEW" | "OFFER", Record<string, number>> = {
  INTERVIEW: {
    MAYBE: 5 - 2,
    PROBABLY: 7 - 2,
    "FOR SURE": 10 - 2,
    "ABSOLUTE CINEMA": 15 - 2,
    "": 10 - 2,
  },
  OFFER: {
    MAYBE: 10 - 2,
    PROBABLY: 14 - 2,
    "FOR SURE": 20 - 2,
    "ABSOLUTE CINEMA": 30 - 2,
    "": 20 - 2,
  },
};

export function scorePoints(status: ApplicationStatus, tag: string | null): number {
  const base = BASE_WEIGHTS[status];
  if (base !== undefined) return base;

  const weights = TAG_WEIGHTS[status as "INTERVIEW" | "OFFER"];
  return weights[tag ?? ""] ?? weights[""];
}

// Priority: OFFER > INTERVIEW > PENDING > GHOSTED > REJECTED
const STATUS_PRIORITY: Record<ApplicationStatus, number> = {
  OFFER: 5,
  INTERVIEW: 4,
  PENDING: 3,
  GHOSTED: 2,
  REJECTED: 1,
};

// TS equivalent of peakStatusSql() — the highest-priority status ever
// reached by an application, replayed over its already-fetched history rows.
export function peakStatus(history: { status: ApplicationStatus }[]): ApplicationStatus | null {
  let peak: ApplicationStatus | null = null;
  let peakPriority = 0;

  for (const { status } of history) {
    const priority = STATUS_PRIORITY[status];
    if (priority > peakPriority) {
      peakPriority = priority;
      peak = status;
    }
  }

  return peak;
}
