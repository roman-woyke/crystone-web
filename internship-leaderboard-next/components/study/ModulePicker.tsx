"use client";

import { useEffect, useRef, useState } from "react";

import type { StudyModuleOption } from "@/lib/study-modules";

export const NEW_MODULE_VALUE = "__new__";

export function ModulePicker({
  modules,
  moduleColors,
  value,
  onChange,
  newModuleName,
  onNewModuleNameChange,
  allowNew = true,
  onDeleteModule,
}: {
  modules: StudyModuleOption[];
  moduleColors: Record<string, string>;
  value: string;
  onChange: (value: string) => void;
  newModuleName: string;
  onNewModuleNameChange: (value: string) => void;
  allowNew?: boolean;
  onDeleteModule?: (name: string) => void;
}) {
  const listRef = useRef<HTMLDivElement>(null);
  const newInputRef = useRef<HTMLInputElement>(null);
  const [hasMore, setHasMore] = useState(false);

  useEffect(() => {
    if (value === NEW_MODULE_VALUE && newInputRef.current) {
      const id = setTimeout(() => newInputRef.current?.focus(), 0);
      return () => clearTimeout(id);
    }
  }, [value]);

  useEffect(() => {
    const el = listRef.current;
    if (!el) return;
    const check = () => {
      const overflowing = el.scrollHeight > el.clientHeight;
      const atBottom = el.scrollTop + el.clientHeight >= el.scrollHeight - 2;
      setHasMore(overflowing && !atBottom);
    };
    check();
    el.addEventListener("scroll", check);
    const raf = requestAnimationFrame(check);
    return () => {
      el.removeEventListener("scroll", check);
      cancelAnimationFrame(raf);
    };
  }, [modules]);

  return (
    <div className="relative">
      <div ref={listRef} className="max-h-56 space-y-1 overflow-y-auto rounded-md border p-1">
        {modules.map((m) => (
          <div
            key={m.name}
            role="button"
            tabIndex={0}
            onClick={() => onChange(m.name)}
            className={`group flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-sm hover:bg-accent ${
              value === m.name ? "bg-accent" : ""
            }`}
          >
            <span
              className="size-2.5 shrink-0 rounded-full"
              style={{ backgroundColor: moduleColors[m.name] ?? "#888" }}
            />
            <span className="flex-1 truncate">{m.name}</span>
            {m.custom && (
              <span className="rounded bg-muted px-1.5 py-0.5 text-[10px] text-muted-foreground">custom</span>
            )}
            {m.custom && onDeleteModule && (
              <button
                type="button"
                title="Remove module"
                className="hidden shrink-0 text-muted-foreground hover:text-destructive group-hover:inline"
                onClick={(e) => {
                  e.stopPropagation();
                  onDeleteModule(m.name);
                }}
              >
                ×
              </button>
            )}
          </div>
        ))}
        {allowNew && (
          <div
            role="button"
            tabIndex={0}
            onClick={() => onChange(NEW_MODULE_VALUE)}
            className={`flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-sm italic text-muted-foreground hover:bg-accent ${
              value === NEW_MODULE_VALUE ? "bg-accent" : ""
            }`}
          >
            <span className="w-2.5 shrink-0 text-center">+</span>
            <span>Add new module…</span>
          </div>
        )}
      </div>
      {hasMore && (
        <div className="pointer-events-none absolute inset-x-0 bottom-0 flex justify-center pb-1 text-muted-foreground">
          ▾
        </div>
      )}

      {allowNew && value === NEW_MODULE_VALUE && (
        <div className="mt-2 space-y-1">
          <input
            ref={newInputRef}
            type="text"
            value={newModuleName}
            onChange={(e) => onNewModuleNameChange(e.target.value)}
            placeholder="New module name"
            maxLength={255}
            className="w-full rounded-md border bg-transparent px-2.5 py-1.5 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
          />
          <p className="text-xs text-muted-foreground">This module will join the shared list for everyone.</p>
        </div>
      )}
    </div>
  );
}
