import { redirect } from "next/navigation";

import { auth } from "@/lib/auth";

// Mirrors the original index.php: it never rendered a marketing page (despite
// older docs describing one) — it's a plain redirect based on session state.
export default async function Home() {
  const session = await auth();
  redirect(session?.user ? "/study" : "/login");
}
