"use client";

import { useState } from "react";

import { fmtTime } from "@/lib/study-format";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";

type StopRow = { module: string | null; full: number; secs: number; removed: boolean };

export function StopModal({
  open,
  parts,
  moduleColors,
  onClose,
  onDiscard,
  onSave,
}: {
  open: boolean;
  parts: { module: string | null; seconds: number }[];
  moduleColors: Record<string, string>;
  onClose: () => void;
  onDiscard: () => void;
  onSave: (sessions: { module: string; seconds: number }[]) => void;
}) {
  const [rows, setRows] = useState<StopRow[]>([]);
  const [prevOpen, setPrevOpen] = useState(open);

  if (open !== prevOpen) {
    setPrevOpen(open);
    if (open) {
      setRows(parts.map((p) => ({ module: p.module, full: p.seconds, secs: p.seconds, removed: false })));
    }
  }

  function update(i: number, fn: (r: StopRow) => StopRow) {
    setRows((prev) => prev.map((r, idx) => (idx === i ? fn(r) : r)));
  }

  function save() {
    const sessions = rows
      .filter((r) => r.module && !r.removed)
      .map((r) => ({ module: r.module as string, seconds: r.secs }));
    onSave(sessions);
  }

  return (
    <Dialog open={open} onOpenChange={(v) => !v && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Log this study session</DialogTitle>
        </DialogHeader>
        <p className="text-sm text-muted-foreground">
          Each module is saved as its own session. Use − to trim time (forgot to stop?), or × to drop a session.
        </p>

        {rows.length === 0 ? (
          <p className="text-sm text-muted-foreground">No study time recorded yet.</p>
        ) : (
          <div className="space-y-2">
            {rows.map((r, i) =>
              r.module === null ? (
                <div key={i} className="flex items-center gap-2 rounded-md border border-dashed px-3 py-2 opacity-50">
                  <span className="flex-1 text-sm">No module · {fmtTime(r.full)} — won&apos;t be saved</span>
                </div>
              ) : (
                <div
                  key={r.module}
                  className={`flex items-center gap-2 rounded-md border px-3 py-2 ${
                    r.removed ? "border-dashed opacity-45" : ""
                  }`}
                >
                  <span
                    className="size-2.5 shrink-0 rounded-full"
                    style={{ backgroundColor: moduleColors[r.module] ?? "#888" }}
                  />
                  <span className={`flex-1 text-sm ${r.removed ? "line-through" : ""}`}>{r.module}</span>
                  <span className="text-sm tabular-nums">{fmtTime(r.secs)}</span>
                  <Button
                    variant="outline"
                    size="icon-sm"
                    title="Remove 5 min"
                    disabled={r.removed}
                    onClick={() => update(i, (row) => ({ ...row, secs: Math.max(60, row.secs - 300) }))}
                  >
                    −
                  </Button>
                  <Button
                    variant="outline"
                    size="icon-sm"
                    title="Add 5 min"
                    disabled={r.removed}
                    onClick={() => update(i, (row) => ({ ...row, secs: Math.min(86400, row.secs + 300) }))}
                  >
                    +
                  </Button>
                  <Button
                    variant="outline"
                    size="icon-sm"
                    title={r.removed ? "Restore" : "Drop this session"}
                    onClick={() => update(i, (row) => ({ ...row, removed: !row.removed }))}
                  >
                    {r.removed ? "↺" : "×"}
                  </Button>
                </div>
              ),
            )}
          </div>
        )}

        <DialogFooter>
          <Button variant="ghost" onClick={onClose}>
            Cancel
          </Button>
          <Button
            variant="destructive"
            onClick={() => {
              onClose();
              onDiscard();
            }}
          >
            Discard all
          </Button>
          <Button
            onClick={() => {
              onClose();
              save();
            }}
          >
            Save
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
