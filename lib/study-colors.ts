const FIXED_USER_COLORS: Record<string, string> = {
  basti: "#22c55e",
  ben: "#ef4444",
  roman: "#8b5cf6",
  lorenz: "#eab308",
};

const USER_COLORS = [
  "#f472b6",
  "#f59e0b",
  "#22d3ee",
  "#a3e635",
  "#fb7185",
  "#c084fc",
  "#facc15",
  "#2dd4bf",
];

const MODULE_COLORS = [
  "#8b5cf6",
  "#3b82f6",
  "#34d399",
  "#fbbf24",
  "#ef4444",
  "#ec4899",
  "#14b8a6",
  "#f97316",
  "#a855f7",
  "#06b6d4",
  "#84cc16",
  "#eab308",
  "#6366f1",
  "#10b981",
  "#d946ef",
  "#f43f5e",
];

const userColorCache = new Map<string, string>();

export function colorFor(username: string): string {
  const cached = userColorCache.get(username);
  if (cached) return cached;

  const fixed = FIXED_USER_COLORS[username.toLowerCase()];
  let color: string;
  if (fixed) {
    color = fixed;
  } else {
    let h = 0;
    for (let i = 0; i < username.length; i++) {
      h = (h * 31 + username.charCodeAt(i)) >>> 0;
    }
    color = USER_COLORS[h % USER_COLORS.length];
  }
  userColorCache.set(username, color);
  return color;
}

export function buildModuleColors(moduleNames: string[]): Record<string, string> {
  const map: Record<string, string> = {};
  moduleNames.forEach((name, i) => {
    map[name] = MODULE_COLORS[i % MODULE_COLORS.length];
  });
  return map;
}

const ABBREV_EXCEPTIONS: Record<string, string> = {
  "softwareentwicklung ii": "SE II",
  "datenbanksysteme i": "DBS I",
  "datenschutz i": "DS I",
  "betriebssysteme i": "BS I",
  "it-sicherheit i": "ITS I",
  rechnerarchitektur: "RA",
};

const ROMAN_RE = /^(I{1,3}|IV|VI{0,3}|IX|X{1,3}|XI{1,3}|XIV|XV|XVI{0,3})$/i;

export function abbrevModule(name: string | null | undefined): string {
  if (!name) return "";

  const exception = ABBREV_EXCEPTIONS[name.toLowerCase()];
  if (exception) return exception;

  const tokens = name
    .split(/[\s-]+/)
    .filter((t) => t !== "" && !["and", "und", "-"].includes(t.toLowerCase()));

  let out = "";
  tokens.forEach((token, i) => {
    if (ROMAN_RE.test(token)) {
      out += (i > 0 ? " " : "") + token.toUpperCase();
    } else {
      out += token[0].toUpperCase();
    }
  });
  return out;
}
