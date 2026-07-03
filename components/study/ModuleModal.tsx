"use client";

import { useState } from "react";

import type { StudyModuleOption } from "@/lib/study-modules";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { ModulePicker, NEW_MODULE_VALUE } from "@/components/study/ModulePicker";

export type ModuleModalState = {
  title: string;
  hint: string;
  confirmLabel: string;
  currentModule: string | null;
  onConfirm: (payload: { module?: string; new_module?: string }) => void;
};

export function ModuleModal({
  state,
  onClose,
  modules,
  moduleColors,
}: {
  state: ModuleModalState | null;
  onClose: () => void;
  modules: StudyModuleOption[];
  moduleColors: Record<string, string>;
}) {
  const [value, setValue] = useState("");
  const [newName, setNewName] = useState("");
  const [prevState, setPrevState] = useState(state);

  if (state !== prevState) {
    setPrevState(state);
    if (state) {
      const hasCurrent = state.currentModule && modules.some((m) => m.name === state.currentModule);
      setValue(hasCurrent ? state.currentModule! : (modules[0]?.name ?? NEW_MODULE_VALUE));
      setNewName("");
    }
  }

  if (!state) return null;

  function confirm() {
    if (!state) return;
    if (value === NEW_MODULE_VALUE) {
      const name = newName.trim();
      if (name === "") return;
      state.onConfirm({ new_module: name });
    } else {
      state.onConfirm({ module: value });
    }
  }

  return (
    <Dialog open={state !== null} onOpenChange={(open) => !open && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{state.title}</DialogTitle>
        </DialogHeader>
        <p className="text-sm text-muted-foreground">{state.hint}</p>
        <ModulePicker
          modules={modules}
          moduleColors={moduleColors}
          value={value}
          onChange={setValue}
          newModuleName={newName}
          onNewModuleNameChange={setNewName}
        />
        <DialogFooter>
          <Button variant="ghost" onClick={onClose}>
            Cancel
          </Button>
          <Button onClick={confirm}>{state.confirmLabel}</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
