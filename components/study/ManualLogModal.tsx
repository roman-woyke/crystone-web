"use client";

import { useState } from "react";

import type { StudyModuleOption } from "@/lib/study-modules";
import { fmtTime, toDateStr } from "@/lib/study-format";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { ModulePicker, NEW_MODULE_VALUE } from "@/components/study/ModulePicker";

function fmtHM(d: Date): string {
  return `${String(d.getHours()).padStart(2, "0")}:${String(d.getMinutes()).padStart(2, "0")}`;
}

function maskTime(raw: string): string {
  const digits = raw.replace(/\D/g, "").slice(0, 4);
  if (digits.length <= 2) return digits;
  return `${digits.slice(0, 2)}:${digits.slice(2)}`;
}

const TIME_RE = /^([01]\d|2[0-3]):[0-5]\d$/;

export function ManualLogModal({
  open,
  onClose,
  modules,
  moduleColors,
  onLogged,
  onDeleteModule,
}: {
  open: boolean;
  onClose: () => void;
  modules: StudyModuleOption[];
  moduleColors: Record<string, string>;
  onLogged: (customAdded: boolean) => void;
  onDeleteModule: (name: string) => void;
}) {
  const [moduleValue, setModuleValue] = useState(modules[0]?.name ?? NEW_MODULE_VALUE);
  const [newModuleName, setNewModuleName] = useState("");
  const [todayMode, setTodayMode] = useState(true);
  const [startTime, setStartTime] = useState("");
  const [endTime, setEndTime] = useState("");
  const [hours, setHours] = useState("");
  const [minutes, setMinutes] = useState("");
  const [date, setDate] = useState(toDateStr(new Date()));
  const [feedback, setFeedback] = useState<{ text: string; error: boolean } | null>(null);
  const [submitting, setSubmitting] = useState(false);

  const todayStr = toDateStr(new Date());

  const [prevOpen, setPrevOpen] = useState(open);
  if (open !== prevOpen) {
    setPrevOpen(open);
    if (open) setFeedback(null);
  }

  const [prevTodayMode, setPrevTodayMode] = useState<boolean | null>(null);
  if (todayMode !== prevTodayMode) {
    setPrevTodayMode(todayMode);
    if (todayMode) {
      setDate(todayStr);
      setEndTime((prev) => prev || fmtHM(new Date()));
      setStartTime((prev) => prev || fmtHM(new Date(Date.now() - 30 * 60_000)));
    }
  }

  async function submit() {
    let modulePayload: { module?: string; new_module?: string };
    if (moduleValue === NEW_MODULE_VALUE) {
      const name = newModuleName.trim();
      if (name === "") {
        setFeedback({ text: "Enter a name for the new module.", error: true });
        return;
      }
      modulePayload = { new_module: name };
    } else {
      modulePayload = { module: moduleValue };
    }

    const body: Record<string, unknown> = { ...modulePayload };

    if (todayMode) {
      if (!TIME_RE.test(startTime) || !TIME_RE.test(endTime)) {
        setFeedback({ text: "Enter start and end as 24h HH:MM.", error: true });
        return;
      }
      body.start_time = startTime;
      body.end_time = endTime;
    } else {
      const seconds = (parseInt(hours || "0", 10) || 0) * 3600 + (parseInt(minutes || "0", 10) || 0) * 60;
      if (seconds <= 0) {
        setFeedback({ text: "Nothing to log yet.", error: true });
        return;
      }
      body.seconds = seconds;
      if (date) body.studied_on = date;
    }

    setSubmitting(true);
    setFeedback({ text: "Saving…", error: false });

    const response = await fetch("/api/study/sessions", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(body),
    });

    setSubmitting(false);

    if (!response.ok) {
      setFeedback({ text: (await response.text()) || "Save failed.", error: true });
      return;
    }

    const res = await response.json();
    setFeedback({ text: `Logged ${fmtTime(res.seconds)} of ${res.module}.`, error: false });
    onLogged(Boolean(res.custom) && moduleValue === NEW_MODULE_VALUE);
  }

  return (
    <Dialog open={open} onOpenChange={(v) => !v && onClose()}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Manual logging</DialogTitle>
        </DialogHeader>

        {feedback && (
          <p className={`text-sm ${feedback.error ? "text-destructive" : "text-muted-foreground"}`}>
            {feedback.text}
          </p>
        )}

        <ModulePicker
          modules={modules}
          moduleColors={moduleColors}
          value={moduleValue}
          onChange={setModuleValue}
          newModuleName={newModuleName}
          onNewModuleNameChange={setNewModuleName}
          onDeleteModule={onDeleteModule}
        />

        <label className="flex items-center gap-2 text-sm">
          <input
            type="checkbox"
            checked={todayMode}
            onChange={(e) => setTodayMode(e.target.checked)}
            className="size-4"
          />
          Today — log exact start &amp; end time
        </label>

        {todayMode ? (
          <div className="grid grid-cols-2 gap-4">
            <div className="space-y-2">
              <Label>Start (24h)</Label>
              <Input
                value={startTime}
                onChange={(e) => setStartTime(maskTime(e.target.value))}
                placeholder="HH:MM"
                maxLength={5}
                pattern="([01]\d|2[0-3]):[0-5]\d"
              />
            </div>
            <div className="space-y-2">
              <Label>End (24h)</Label>
              <Input
                value={endTime}
                onChange={(e) => setEndTime(maskTime(e.target.value))}
                placeholder="HH:MM"
                maxLength={5}
                pattern="([01]\d|2[0-3]):[0-5]\d"
              />
            </div>
          </div>
        ) : (
          <div className="grid grid-cols-2 gap-4">
            <div className="space-y-2">
              <Label>Hours</Label>
              <Input type="number" min={0} value={hours} onChange={(e) => setHours(e.target.value)} />
            </div>
            <div className="space-y-2">
              <Label>Minutes</Label>
              <Input type="number" min={0} max={59} value={minutes} onChange={(e) => setMinutes(e.target.value)} />
            </div>
          </div>
        )}

        <div className="space-y-2">
          <Label>Date</Label>
          <Input
            type="date"
            value={date}
            max={todayStr}
            disabled={todayMode}
            onChange={(e) => setDate(e.target.value)}
          />
        </div>

        <DialogFooter>
          <Button onClick={submit} disabled={submitting}>
            Log session
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
