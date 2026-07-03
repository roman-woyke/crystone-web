import { prisma } from "@/lib/prisma";

export async function allowedStudyModules(): Promise<{ examTitles: string[]; all: string[] }> {
  const exams = await prisma.exam.findMany({ select: { title: true }, distinct: ["title"] });
  const custom = await prisma.studyModule.findMany({ select: { name: true } });
  const examTitles = exams.map((e) => e.title);
  return { examTitles, all: [...examTitles, ...custom.map((c) => c.name)] };
}

export type StudyModuleOption = { name: string; custom: boolean };

// Module list: exam-calendar titles (defaults) + custom study modules,
// de-duplicated case-insensitively (exam titles win on a name collision).
export async function getStudyModulesList(): Promise<StudyModuleOption[]> {
  const examTitles = await prisma.exam.findMany({
    select: { title: true },
    distinct: ["title"],
    orderBy: { title: "asc" },
  });
  const customModules = await prisma.studyModule.findMany({
    select: { name: true },
    orderBy: { name: "asc" },
  });

  const modules: StudyModuleOption[] = [];
  const seen = new Set<string>();
  for (const e of examTitles) {
    const key = e.title.toLowerCase();
    if (seen.has(key)) continue;
    seen.add(key);
    modules.push({ name: e.title, custom: false });
  }
  for (const m of customModules) {
    const key = m.name.toLowerCase();
    if (seen.has(key)) continue;
    seen.add(key);
    modules.push({ name: m.name, custom: true });
  }
  return modules;
}

// Resolve a module name against the allowed set (exam titles + custom
// modules), creating a new custom module when the name is genuinely new.
export async function resolveStudyModule(
  userId: number,
  module: string,
  newModule: string,
): Promise<{ resolved: string | null; customAdded: boolean }> {
  const { all } = await allowedStudyModules();

  if (newModule !== "") {
    if (newModule.length > 255) return { resolved: null, customAdded: false };
    const existing = all.find((m) => m.toLowerCase() === newModule.toLowerCase());
    if (existing) return { resolved: existing, customAdded: false };
    await prisma.studyModule.create({ data: { name: newModule, createdBy: userId } });
    return { resolved: newModule, customAdded: true };
  }

  const existing = all.find((m) => m.toLowerCase() === module.toLowerCase());
  return { resolved: existing ?? null, customAdded: false };
}
