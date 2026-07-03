"use client";

import { useRef, useState } from "react";
import { useRouter } from "next/navigation";

import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";

function resizeToSquareJpeg(file: File): Promise<string> {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onerror = () => reject(new Error("Could not read file."));
    reader.onload = () => {
      const img = new Image();
      img.onerror = () => reject(new Error("Could not read image."));
      img.onload = () => {
        const size = 256;
        const canvas = document.createElement("canvas");
        canvas.width = size;
        canvas.height = size;
        const ctx = canvas.getContext("2d")!;
        const side = Math.min(img.width, img.height);
        const sx = (img.width - side) / 2;
        const sy = (img.height - side) / 2;
        ctx.drawImage(img, sx, sy, side, side, 0, 0, size, size);
        resolve(canvas.toDataURL("image/jpeg", 0.85));
      };
      img.src = String(reader.result);
    };
    reader.readAsDataURL(file);
  });
}

function AvatarVisual({ avatar, initial, big }: { avatar: string | null; initial: string; big?: boolean }) {
  if (avatar) {
    // eslint-disable-next-line @next/next/no-img-element
    return <img src={avatar} alt="" className="size-full rounded-full object-cover" />;
  }
  return (
    <span
      className={`flex size-full items-center justify-center rounded-full bg-muted font-semibold ${big ? "text-3xl" : "text-sm"}`}
    >
      {initial}
    </span>
  );
}

export function AvatarButton({ username, avatar }: { username: string; avatar: string | null }) {
  const router = useRouter();
  const [open, setOpen] = useState(false);
  const [currentAvatar, setCurrentAvatar] = useState(avatar);
  const [pending, setPending] = useState<string | null>(null);
  const [feedback, setFeedback] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const fileInputRef = useRef<HTMLInputElement>(null);

  const initial = (username[0] ?? "?").toUpperCase();

  function openDialog() {
    setPending(null);
    setFeedback(null);
    setOpen(true);
  }

  async function handleFileChange(event: React.ChangeEvent<HTMLInputElement>) {
    const file = event.target.files?.[0];
    if (!file) return;
    try {
      const dataUrl = await resizeToSquareJpeg(file);
      setPending(dataUrl);
    } catch (error) {
      setFeedback(error instanceof Error ? error.message : "Could not read image.");
    } finally {
      event.target.value = "";
    }
  }

  async function save() {
    if (!pending) return;
    setBusy(true);
    setFeedback("Saving…");

    const response = await fetch("/api/avatar", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ avatar: pending }),
    });

    setBusy(false);

    if (!response.ok) {
      setFeedback(await response.text());
      return;
    }

    const result = await response.json();
    setCurrentAvatar(result.avatar);
    setFeedback("Saved.");
    router.refresh();
    setTimeout(() => setOpen(false), 600);
  }

  async function remove() {
    setBusy(true);
    setFeedback("Removing…");

    const response = await fetch("/api/avatar", { method: "DELETE" });

    setBusy(false);

    if (!response.ok) {
      setFeedback(await response.text());
      return;
    }

    setCurrentAvatar(null);
    setPending(null);
    setFeedback("Removed.");
    router.refresh();
  }

  return (
    <>
      <button
        type="button"
        onClick={openDialog}
        title="Profile picture"
        aria-label="Edit profile picture"
        className="size-9 shrink-0 overflow-hidden rounded-full ring-1 ring-border"
      >
        <AvatarVisual avatar={currentAvatar} initial={initial} />
      </button>

      <Dialog open={open} onOpenChange={setOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Profile picture</DialogTitle>
          </DialogHeader>

          <div className="mx-auto size-32 overflow-hidden rounded-full ring-1 ring-border">
            <AvatarVisual avatar={pending ?? currentAvatar} initial={initial} big />
          </div>

          <p className="text-center text-sm text-muted-foreground">
            PNG, JPG or WebP — it&apos;s cropped to a square and resized.
          </p>

          <input
            ref={fileInputRef}
            type="file"
            accept="image/png,image/jpeg,image/webp"
            hidden
            onChange={handleFileChange}
          />

          {feedback && <p className="text-center text-sm text-muted-foreground">{feedback}</p>}

          <DialogFooter>
            <Button variant="destructive" onClick={remove} disabled={busy}>
              Remove
            </Button>
            <Button variant="outline" onClick={() => fileInputRef.current?.click()} disabled={busy}>
              Choose image
            </Button>
            <Button onClick={save} disabled={busy || !pending}>
              Save
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}
