// Word lists for fWordle — bundled plain text (not in the DB), ported 1:1
// from includes/fwordle.php. dict-<len>.txt is the full SOWPODS Scrabble
// list (guess validation); answers-<len>.txt is common words intersected
// with SOWPODS (answer pool + suggestions).

import { createHash } from "crypto";
import { readFileSync } from "fs";
import path from "path";

export const FWORDLE_MAX_WORDS = 4;
export const FWORDLE_MIN_LEN = 5;
export const FWORDLE_MAX_LEN = 10;

function wordsDir(): string {
  return path.join(process.cwd(), "includes", "fwordle");
}

function readWordList(file: string): string[] {
  try {
    return readFileSync(path.join(wordsDir(), file), "utf-8")
      .split(/\r?\n/)
      .map((w) => w.trim())
      .filter((w) => w.length > 0);
  } catch {
    return [];
  }
}

const dictCache = new Map<number, Set<string>>();
function dictSet(len: number): Set<string> {
  let set = dictCache.get(len);
  if (!set) {
    set = new Set(readWordList(`dict-${len}.txt`));
    dictCache.set(len, set);
  }
  return set;
}

const answerCache = new Map<number, string[]>();
function answerPool(len: number): string[] {
  let pool = answerCache.get(len);
  if (!pool) {
    pool = readWordList(`answers-${len}.txt`);
    answerCache.set(len, pool);
  }
  return pool;
}

export function fwordleIsValidWord(word: string, len: number): boolean {
  const w = word.toLowerCase();
  if (len < FWORDLE_MIN_LEN || len > FWORDLE_MAX_LEN) return false;
  if (w.length !== len || !/^[a-z]+$/.test(w)) return false;
  return dictSet(len).has(w);
}

// A random answer of the given length, avoiding the supplied words.
export function fwordleRandomAnswer(len: number, exclude: string[] = []): string | null {
  const pool = answerPool(len);
  if (pool.length === 0) return null;
  const excludeSet = new Set(exclude.map((w) => w.toLowerCase()));
  const shuffled = [...pool];
  for (let i = shuffled.length - 1; i > 0; i--) {
    const j = Math.floor(Math.random() * (i + 1));
    [shuffled[i], shuffled[j]] = [shuffled[j], shuffled[i]];
  }
  for (const w of shuffled) {
    if (!excludeSet.has(w)) return w;
  }
  return shuffled[0];
}

// Three stable per-user suggestions of the given length (don't reshuffle on
// every poll — ordered deterministically by a per-user/day hash).
export function fwordleSuggestions(len: number, userId: number, forDate: string, count = 3): string[] {
  const pool = [...new Set(answerPool(len))];
  if (pool.length === 0) return [];
  const seed = `${userId}|${forDate}`;
  const keyed = pool.map((w) => ({ w, k: createHash("md5").update(w + seed).digest("hex") }));
  keyed.sort((a, b) => (a.k < b.k ? -1 : a.k > b.k ? 1 : 0));
  return keyed.slice(0, count).map((x) => x.w);
}

// Weighted random length: 5->30%, 6->30%, 7->20%, 8->10%, 9->5%, 10->5%.
export function fwordleRollLength(): number {
  const weights: [number, number][] = [
    [5, 30],
    [6, 30],
    [7, 20],
    [8, 10],
    [9, 5],
    [10, 5],
  ];
  const r = 1 + Math.floor(Math.random() * 100);
  let cum = 0;
  for (const [len, w] of weights) {
    cum += w;
    if (r <= cum) return len;
  }
  return FWORDLE_MIN_LEN;
}

// Shared guesses for a day: 5 + numBoards -> 9 for the fixed 4 boards.
// Length-independent.
export function fwordleMaxGuesses(numBoards: number): number {
  return 5 + numBoards;
}
