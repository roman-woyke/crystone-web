"use client";

import { useState } from "react";

import { Button } from "@/components/ui/button";
import type { ChooseInfo } from "@/lib/boardle";

// Tomorrow's (or, in the "late pick" case, today's) word picker — shown once
// the viewer has earned a slot. Holds its own local edit buffer so a
// background poll never wipes what's being typed.
export function ChooseWord({
  choose,
  onSubmit,
}: {
  choose: ChooseInfo;
  onSubmit: (word: string, hint: string) => Promise<void>;
}) {
  const [changing, setChanging] = useState(false);
  const [custom, setCustom] = useState("");
  const [hintText, setHintText] = useState(choose.already_hint ?? "");
  const [error, setError] = useState<string | null>(null);
  const [revealed, setRevealed] = useState(false);
  // Rerolled suggestions from the randomize button — kept locally so a
  // background poll (which refreshes `choose`) doesn't reset them.
  const [suggestOverride, setSuggestOverride] = useState<string[] | null>(null);

  const tlen = choose.tomorrow_length ?? 0;
  const suggestions = suggestOverride ?? choose.suggestions;

  function startChanging() {
    setCustom(choose.already ?? "");
    setHintText(choose.already_hint ?? "");
    setError(null);
    setChanging(true);
  }

  async function submit(word: string) {
    const w = word.trim().toLowerCase();
    if (!/^[a-z]+$/.test(w) || w.length !== tlen) {
      setError(`Needs to be a ${tlen}-letter word.`);
      return;
    }
    setError(null);
    await onSubmit(w, hintText.trim());
    setChanging(false);
  }

  // Reroll the three suggestions (the server picks fresh random words of the
  // right length).
  async function randomize() {
    try {
      const res = await fetch("/api/boardle/suggest");
      if (!res.ok) throw new Error((await res.text()) || "Could not shuffle.");
      const data: { suggestions: string[] } = await res.json();
      setSuggestOverride(data.suggestions ?? []);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Could not shuffle.");
    }
  }

  if (choose.already && !changing) {
    return (
      <div className="glow-card overflow-hidden rounded-xl border bg-card p-5">
        <h2 className="mb-1 text-lg font-semibold">{choose.for_today ? "🗳️ Today's word" : "🗳️ Tomorrow's word"}</h2>
        <div className="rounded-lg border border-primary/30 bg-primary/5 p-4">
          <span className="inline-flex flex-wrap items-center gap-2.5">
            Your pick:
            <strong className={`font-mono tracking-widest uppercase ${revealed ? "" : "blur-sm select-none"}`}>{choose.already}</strong>
            <Button type="button" size="sm" variant="outline" onClick={() => setRevealed((r) => !r)}>
              {revealed ? "Hide" : "Reveal"}
            </Button>
          </span>
          {choose.already_hint && (
            <p className="mt-2 text-sm whitespace-pre-wrap text-muted-foreground">
              <span className="font-bold">Your hint:</span> {choose.already_hint}
            </p>
          )}
          <div className="mt-2">
            <Button type="button" size="sm" variant="outline" onClick={startChanging}>
              Change it
            </Button>
          </div>
        </div>
        <p className="mt-2.5 min-h-[1.1em] text-sm text-emerald-500">
          {choose.for_today
            ? "Saved. You can still change it until the first guess is made."
            : "Saved. You can change it until tomorrow begins."}
        </p>
      </div>
    );
  }

  return (
    <div className="glow-card overflow-hidden rounded-xl border bg-card p-5">
      <h2 className="mb-1 text-lg font-semibold">
        {choose.for_today ? "The day just started — still time to set your word!" : "🎉 Set a word for tomorrow"}
      </h2>
      <p className="mb-3.5 text-sm text-muted-foreground">
        {choose.for_today
          ? `Today is a ${tlen}-letter day. Pick a suggestion or enter your own (must be a real ${tlen}-letter word). Once someone guesses, the word is final.`
          : `Tomorrow is a ${tlen}-letter day. Pick a suggestion or enter your own (must be a real ${tlen}-letter word).`}
      </p>
      {/* Fixed-width suggestion buttons (wide enough for the max 10-letter
          word) so a reroll never reflows the row. */}
      <div className="mb-3.5 flex flex-wrap items-center gap-2.5">
        {suggestions.map((w) => (
          <Button
            key={w}
            type="button"
            variant="outline"
            className="w-32 flex-none overflow-hidden font-mono tracking-widest text-ellipsis uppercase"
            onClick={() => submit(w)}
          >
            {w}
          </Button>
        ))}
        <Button
          type="button"
          variant="outline"
          className="w-10 flex-none px-0"
          title="Get three new suggestions"
          aria-label="Randomize suggestions"
          onClick={randomize}
        >
          🎲
        </Button>
      </div>
      <div className="flex flex-wrap items-stretch gap-2.5">
        <input
          type="text"
          maxLength={tlen}
          value={custom}
          onChange={(e) => setCustom(e.target.value)}
          onKeyDown={(e) => {
            if (e.key === "Enter") {
              e.preventDefault();
              submit(custom);
            }
          }}
          placeholder={`Your own ${tlen}-letter word`}
          className="min-w-40 flex-1 rounded-md border bg-background px-3 py-2 font-mono tracking-widest uppercase"
        />
        <Button type="button" onClick={() => submit(custom)}>
          Set word
        </Button>
      </div>
      <div className="mt-3.5">
        <label htmlFor="fw-hint" className="mb-1.5 block text-sm text-muted-foreground">
          Hint for players (optional — shown under their board from the start):
        </label>
        <textarea
          id="fw-hint"
          value={hintText}
          onChange={(e) => setHintText(e.target.value)}
          placeholder="Give players a clue without spoiling the word..."
          className="min-h-20 w-full resize-y rounded-md border bg-background px-3 py-2 text-sm"
        />
      </div>
      {error && <p className="mt-2.5 min-h-[1.1em] text-sm text-destructive">{error}</p>}
    </div>
  );
}
