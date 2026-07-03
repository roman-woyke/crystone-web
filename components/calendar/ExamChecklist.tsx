"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";

export type ChecklistExam = {
  id: number;
  title: string;
  professor: string | null;
  meta: string;
};

export function ExamChecklist({
  exams,
  selectedIds,
  canEdit,
  targetUsername,
}: {
  exams: ChecklistExam[];
  selectedIds: number[];
  canEdit: boolean;
  targetUsername: string;
}) {
  const router = useRouter();
  const [selected, setSelected] = useState(new Set(selectedIds));
  const [pending, setPending] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);

  async function toggle(examId: number, checked: boolean) {
    setError(null);
    setPending(examId);

    setSelected((prev) => {
      const next = new Set(prev);
      if (checked) next.add(examId);
      else next.delete(examId);
      return next;
    });

    const response = await fetch("/api/exams/toggle", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ username: targetUsername, exam_id: examId, checked: checked ? "1" : "0" }),
    });

    setPending(null);

    if (!response.ok) {
      setSelected((prev) => {
        const next = new Set(prev);
        if (checked) next.delete(examId);
        else next.add(examId);
        return next;
      });
      setError(await response.text());
      return;
    }

    router.refresh();
  }

  return (
    <div className="space-y-2">
      {error && <p className="text-xs text-destructive">{error}</p>}
      <ul className="flex max-h-[520px] flex-col gap-1.5 overflow-y-auto">
        {exams.map((e) => (
          <li key={e.id} className="flex items-start gap-2.5 rounded-md border px-2.5 py-2 text-sm">
            <input
              type="checkbox"
              id={`exam-${e.id}`}
              checked={selected.has(e.id)}
              disabled={!canEdit || pending === e.id}
              onChange={(event) => toggle(e.id, event.target.checked)}
              className="mt-0.5 size-4 shrink-0"
            />
            <label htmlFor={`exam-${e.id}`} className={canEdit ? "cursor-pointer" : ""}>
              <span className="block font-medium">{e.title}</span>
              <span className="block text-xs text-muted-foreground">
                {e.professor}
                {e.professor ? <br /> : null}
                {e.meta}
              </span>
            </label>
          </li>
        ))}
      </ul>
    </div>
  );
}
