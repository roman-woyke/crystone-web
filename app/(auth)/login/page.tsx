import Link from "next/link";
import { redirect } from "next/navigation";
import { AuthError } from "next-auth";

import { auth, signIn } from "@/lib/auth";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { AuthShell } from "@/components/layout/AuthShell";

// Only accept `next` if it's a same-app relative path (prevents open redirects).
function safeNext(next: string | undefined): string {
  if (!next || !next.startsWith("/") || next.startsWith("//")) return "/dashboard";
  return next;
}

export default async function LoginPage({
  searchParams,
}: {
  searchParams: Promise<{ next?: string; error?: string }>;
}) {
  const params = await searchParams;
  const next = safeNext(params.next);

  const session = await auth();
  if (session?.user) {
    redirect(next);
  }

  async function login(formData: FormData) {
    "use server";

    const next = safeNext(String(formData.get("next") ?? ""));
    const username = String(formData.get("username") ?? "").trim();
    const password = String(formData.get("password") ?? "");

    if (username === "" || password === "") {
      redirect(`/login?next=${encodeURIComponent(next)}&error=1`);
    }

    try {
      await signIn("credentials", { username, password, redirectTo: next });
    } catch (error) {
      if (error instanceof AuthError) {
        redirect(`/login?next=${encodeURIComponent(next)}&error=1`);
      }
      throw error;
    }
  }

  return (
    <AuthShell>
      <div className="rise space-y-2">
        <h2 className="font-heading text-3xl font-bold tracking-tight">Welcome back</h2>
        <p className="text-sm text-muted-foreground">Log in to keep climbing the leaderboard.</p>
      </div>

      <div className="rise rise-1 mt-8">
        {params.error && (
          <p className="mb-4 rounded-lg border border-destructive/30 bg-destructive/10 px-3 py-2 text-sm text-destructive">
            Invalid username or password.
          </p>
        )}

        <form action={login} className="space-y-5">
          <input type="hidden" name="next" value={next} />
          <div className="space-y-2">
            <Label htmlFor="username">Username</Label>
            <Input id="username" name="username" type="text" autoComplete="username" required />
          </div>
          <div className="space-y-2">
            <Label htmlFor="password">Password</Label>
            <Input
              id="password"
              name="password"
              type="password"
              autoComplete="current-password"
              required
            />
          </div>
          <Button type="submit" size="lg" className="w-full">
            Login
          </Button>
        </form>

        <p className="mt-6 text-center text-sm text-muted-foreground">
          No account yet?{" "}
          <Link
            href="/register"
            className="font-medium text-primary underline-offset-4 transition-colors hover:text-foreground hover:underline"
          >
            Register
          </Link>
        </p>
      </div>
    </AuthShell>
  );
}
