"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";

import { ApplicationStatus } from "@/app/generated/prisma/enums";
import { Button } from "@/components/ui/button";
import { Textarea } from "@/components/ui/textarea";
import { Input } from "@/components/ui/input";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";

const NO_TAG = "__none__";
const TAGS = ["MAYBE", "PROBABLY", "FOR SURE", "ABSOLUTE CINEMA"];
const STATUSES: ApplicationStatus[] = [
  ApplicationStatus.PENDING,
  ApplicationStatus.REJECTED,
  ApplicationStatus.GHOSTED,
  ApplicationStatus.INTERVIEW,
  ApplicationStatus.OFFER,
];

export type ApplicationRow = {
  id: number;
  companyName: string;
  jobTitle: string | null;
  jobLink: string | null;
  location: string | null;
  status: ApplicationStatus;
  tag: string | null;
  notes: string | null;
  peakStatus: ApplicationStatus | null;
  createdLabel: string;
  lastUpdateLabel: string;
};

async function patchApplication(id: number, data: Record<string, string | null>) {
  const response = await fetch(`/api/applications/${id}`, {
    method: "PATCH",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(data),
  });
  if (!response.ok) {
    throw new Error(await response.text());
  }
}

export function ApplicationsTable({ applications }: { applications: ApplicationRow[] }) {
  const router = useRouter();
  const [rows, setRows] = useState(applications);
  const [dirty, setDirty] = useState<Record<string, boolean>>({});
  const [editTarget, setEditTarget] = useState<{
    id: number;
    field: "job_link" | "notes";
    value: string;
  } | null>(null);

  if (rows.length === 0) {
    return <p className="text-muted-foreground">No applications yet — add your first one above.</p>;
  }

  function setField(id: number, field: "status" | "tag", value: string | null) {
    setRows((prev) =>
      prev.map((row) =>
        row.id === id ? { ...row, [field]: !value ? null : value } : row,
      ),
    );
    setDirty((prev) => ({ ...prev, [`${id}:${field}`]: true }));
  }

  async function save(id: number, field: "status" | "tag") {
    const row = rows.find((r) => r.id === id);
    if (!row) return;

    try {
      await patchApplication(id, { [field]: row[field] ?? "" });
      setDirty((prev) => ({ ...prev, [`${id}:${field}`]: false }));
      router.refresh();
    } catch (error) {
      alert(error instanceof Error ? error.message : "Save failed.");
    }
  }

  async function saveEdit() {
    if (!editTarget) return;
    try {
      await patchApplication(editTarget.id, { [editTarget.field]: editTarget.value });
      setRows((prev) =>
        prev.map((row) =>
          row.id === editTarget.id
            ? {
                ...row,
                ...(editTarget.field === "job_link"
                  ? { jobLink: editTarget.value || null }
                  : { notes: editTarget.value || null }),
              }
            : row,
        ),
      );
      setEditTarget(null);
      router.refresh();
    } catch (error) {
      alert(error instanceof Error ? error.message : "Save failed.");
    }
  }

  async function remove(id: number) {
    if (!confirm("Delete this application? This cannot be undone.")) return;

    const response = await fetch(`/api/applications/${id}`, { method: "DELETE" });
    if (!response.ok) {
      alert(await response.text());
      return;
    }

    setRows((prev) => prev.filter((row) => row.id !== id));
    router.refresh();
  }

  return (
    <>
      <div data-no-tilt className="glow-card overflow-x-auto rounded-md border bg-card">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Tag</TableHead>
              <TableHead>Company</TableHead>
              <TableHead>Job</TableHead>
              <TableHead>Location</TableHead>
              <TableHead>Status</TableHead>
              <TableHead>Link</TableHead>
              <TableHead>Notes</TableHead>
              <TableHead>Created</TableHead>
              <TableHead>Last update</TableHead>
              <TableHead />
            </TableRow>
          </TableHeader>
          <TableBody>
            {rows.map((row) => (
              <TableRow key={row.id}>
                <TableCell>
                  <div className="flex items-center gap-2">
                    <Select
                      value={row.tag ?? NO_TAG}
                      onValueChange={(value) => setField(row.id, "tag", value === NO_TAG ? "" : value)}
                    >
                      <SelectTrigger className="min-w-[140px]">
                        <SelectValue placeholder="— none —" />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value={NO_TAG}>— none —</SelectItem>
                        {TAGS.map((tag) => (
                          <SelectItem key={tag} value={tag}>
                            {tag}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                    {dirty[`${row.id}:tag`] && (
                      <Button size="sm" onClick={() => save(row.id, "tag")}>
                        Save
                      </Button>
                    )}
                  </div>
                </TableCell>

                <TableCell className="font-medium">{row.companyName}</TableCell>
                <TableCell>{row.jobTitle}</TableCell>
                <TableCell>{row.location}</TableCell>

                <TableCell>
                  <div className="flex items-center gap-2">
                    <div className="flex flex-col gap-1">
                      {row.peakStatus &&
                        row.peakStatus !== row.status &&
                        (row.peakStatus === "INTERVIEW" || row.peakStatus === "OFFER") && (
                          <span className="text-xs text-muted-foreground">
                            <strong>{row.peakStatus}</strong> ↓
                          </span>
                        )}
                      <Select
                        value={row.status}
                        onValueChange={(value) => setField(row.id, "status", value)}
                      >
                        <SelectTrigger className="min-w-[130px]">
                          <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                          {STATUSES.map((status) => (
                            <SelectItem key={status} value={status}>
                              {status}
                            </SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                    </div>
                    {dirty[`${row.id}:status`] && (
                      <Button size="sm" onClick={() => save(row.id, "status")}>
                        Save
                      </Button>
                    )}
                  </div>
                </TableCell>

                <TableCell>
                  <div className="flex items-center gap-2">
                    {row.jobLink ? (
                      <a
                        href={row.jobLink}
                        target="_blank"
                        rel="noreferrer"
                        className="underline underline-offset-4"
                      >
                        Open
                      </a>
                    ) : (
                      <span className="text-muted-foreground">—</span>
                    )}
                    <Button
                      variant="ghost"
                      size="icon"
                      className="size-6"
                      aria-label="Edit link"
                      onClick={() =>
                        setEditTarget({ id: row.id, field: "job_link", value: row.jobLink ?? "" })
                      }
                    >
                      ✎
                    </Button>
                  </div>
                </TableCell>

                <TableCell>
                  <div className="flex items-center gap-2">
                    <span className="max-w-[200px] truncate">
                      {(row.notes ?? "").length > 40
                        ? `${(row.notes ?? "").slice(0, 40)}…`
                        : row.notes}
                    </span>
                    <Button
                      variant="ghost"
                      size="icon"
                      className="size-6"
                      aria-label="Edit notes"
                      onClick={() =>
                        setEditTarget({ id: row.id, field: "notes", value: row.notes ?? "" })
                      }
                    >
                      ✎
                    </Button>
                  </div>
                </TableCell>

                <TableCell>{row.createdLabel}</TableCell>
                <TableCell>{row.lastUpdateLabel}</TableCell>

                <TableCell>
                  <Button variant="destructive" size="sm" onClick={() => remove(row.id)}>
                    Delete
                  </Button>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </div>

      <Dialog open={editTarget !== null} onOpenChange={(open) => !open && setEditTarget(null)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{editTarget?.field === "notes" ? "Edit notes" : "Edit link"}</DialogTitle>
          </DialogHeader>
          {editTarget?.field === "notes" ? (
            <Textarea
              value={editTarget.value}
              onChange={(e) => setEditTarget({ ...editTarget, value: e.target.value })}
            />
          ) : (
            <Input
              type="url"
              value={editTarget?.value ?? ""}
              onChange={(e) => editTarget && setEditTarget({ ...editTarget, value: e.target.value })}
            />
          )}
          <DialogFooter>
            <Button variant="ghost" onClick={() => setEditTarget(null)}>
              Cancel
            </Button>
            <Button onClick={saveEdit}>Save</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}
