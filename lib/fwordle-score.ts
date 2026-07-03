// Scoring + board-replay logic for fWordle, ported 1:1 from includes/fwordle.php.

export type CellColor = "green" | "orange" | "grey" | "empty";

// Score a guess string (L chars, '_' = blank/unused cell) against an answer.
// Standard Wordle letter-multiplicity handling (greens claimed first).
export function fwordleScore(answer: string, guess: string): CellColor[] {
  const a = answer.toLowerCase();
  const g = guess.toLowerCase();
  const L = a.length;
  const res: CellColor[] = new Array(L).fill("empty");

  const remaining = new Map<string, number>();
  for (let i = 0; i < L; i++) {
    const ch = g[i] ?? "_";
    if (ch !== "_" && ch === a[i]) {
      res[i] = "green";
    } else {
      remaining.set(a[i], (remaining.get(a[i]) ?? 0) + 1);
    }
  }
  for (let i = 0; i < L; i++) {
    const ch = g[i] ?? "_";
    if (ch === "_" || res[i] === "green") continue;
    const left = remaining.get(ch) ?? 0;
    if (left > 0) {
      res[i] = "orange";
      remaining.set(ch, left - 1);
    } else {
      res[i] = "grey";
    }
  }
  return res;
}

export type BoardGuessRow = { text: string; boards: CellColor[][] };
export type UserBoardsResult = {
  guesses: BoardGuessRow[];
  solvedBoards: boolean[];
  solved: boolean;
};

// Replay a user's guesses across all boards. `owned` are board positions the
// user picked the word for — those count as solved without guessing (they
// already know the word), so they don't block the win.
export function fwordleUserBoards(guesses: string[], answers: string[], owned: number[] = []): UserBoardsResult {
  const num = answers.length;
  const solvedBoards = new Array(num).fill(false);
  for (const p of owned) {
    if (p >= 0 && p < num) solvedBoards[p] = true;
  }
  const rows: BoardGuessRow[] = [];
  for (const g of guesses) {
    const boards: CellColor[][] = [];
    for (let pos = 0; pos < num; pos++) {
      boards[pos] = fwordleScore(answers[pos], g);
      if (g.toLowerCase() === answers[pos].toLowerCase()) solvedBoards[pos] = true;
    }
    rows.push({ text: g, boards });
  }
  const solved = num > 0 && solvedBoards.every(Boolean);
  return { guesses: rows, solvedBoards, solved };
}

// Fixed board order so the numbering is stable per player (independent of who
// solved first): roman -> ben -> basti -> lorenz, others last.
export function fwordlePlayerRank(username: string): number {
  const order: Record<string, number> = { roman: 0, ben: 1, basti: 2, lorenz: 3 };
  return order[username.toLowerCase()] ?? 99;
}

type BoardKnowledge = { present: Set<string>; absent: Set<string>; greenPos: Set<number> };

// What a player already knows about one board, derived from their guesses.
function fwordleBoardKnowledge(guesses: string[], answer: string): BoardKnowledge {
  const a = answer.toLowerCase();
  const len = a.length;
  const present = new Set<string>();
  const absent = new Set<string>();
  const greenPos = new Set<number>();
  for (const g of guesses) {
    const colors = fwordleScore(a, g);
    for (let i = 0; i < len; i++) {
      const ch = g[i] ?? "_";
      if (ch === "_") continue;
      if (colors[i] === "green") {
        present.add(ch);
        greenPos.add(i);
      } else if (colors[i] === "orange") {
        present.add(ch);
      } else if (colors[i] === "grey" && !a.includes(ch)) {
        absent.add(ch);
      }
    }
  }
  return { present, absent, greenPos };
}

function randomInt(n: number): number {
  return Math.floor(Math.random() * n);
}

// Compute a board-joker reveal, or null if there's nothing new to reveal.
// Encodes one or more "letter:position" cells joined by commas:
//   orange -> up to 2 cells, each a present letter shown on a spot it ISN'T
//             (so it reads as a true orange cell), e.g. "r:2,t:5"
//   green  -> 1 cell, the correct letter at its spot (0-indexed), e.g. "r:3"
export function fwordleComputeHint(answer: string, guesses: string[], type: "orange" | "green"): string | null {
  const a = answer.toLowerCase();
  const len = a.length;
  const k = fwordleBoardKnowledge(guesses, a);

  if (type === "orange") {
    const cands = [...new Set(a.split(""))].filter((ch) => !k.present.has(ch));
    if (cands.length === 0) return null;
    for (let i = cands.length - 1; i > 0; i--) {
      const j = randomInt(i + 1);
      [cands[i], cands[j]] = [cands[j], cands[i]];
    }
    const picked = cands.slice(0, 2);
    const usedPos = new Set<number>();
    const cells: string[] = [];
    for (const ch of picked) {
      const spots: number[] = [];
      for (let i = 0; i < len; i++) {
        if (a[i] !== ch && !usedPos.has(i)) spots.push(i);
      }
      if (spots.length === 0) continue;
      const p = spots[randomInt(spots.length)];
      usedPos.add(p);
      cells.push(`${ch}:${p}`);
    }
    return cells.length > 0 ? cells.join(",") : null;
  }

  // green
  const cands: number[] = [];
  for (let i = 0; i < len; i++) {
    if (!k.greenPos.has(i)) cands.push(i);
  }
  if (cands.length === 0) return null;
  const pos = cands[randomInt(cands.length)];
  return `${a[pos]}:${pos}`;
}

export type ParsedHint = {
  board: number | null;
  type: "armor" | "orange" | "green";
  cells: { letter: string; pos: number }[];
};

type HintRow = { type: "armor" | "orange" | "green"; boardPos: number | null; payload: string };

// Parse a stored hint row into the client shape. Armor is board-less; orange
// and green carry a `cells` array of {letter, pos} (orange up to 2, green 1).
export function fwordleParseHint(h: HintRow): ParsedHint {
  if (h.type === "armor") {
    return { board: null, type: "armor", cells: [] };
  }
  const cells: { letter: string; pos: number }[] = [];
  for (const part of h.payload.split(",")) {
    if (part === "") continue;
    if (part.includes(":")) {
      const [l, p] = part.split(":");
      cells.push({ letter: l, pos: Number(p) });
    } else {
      cells.push({ letter: part, pos: 0 });
    }
  }
  return { board: h.boardPos, type: h.type, cells };
}
