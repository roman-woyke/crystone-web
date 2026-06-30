import Link from "next/link";
import { redirect } from "next/navigation";
import { AuthError } from "next-auth";

import { auth, signIn } from "@/lib/auth";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";

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
    <div className="flex min-h-screen items-center justify-center p-4">
      <Card className="w-full max-w-sm">
        <CardHeader>
          <CardTitle className="text-2xl">Welcome back</CardTitle>
          <CardDescription>Log in to keep climbing the leaderboard.</CardDescription>
        </CardHeader>
        <CardContent>
          {params.error && (
            <p className="mb-4 rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive">
              Invalid username or password.
            </p>
          )}

          <form action={login} className="space-y-4">
            <input type="hidden" name="next" value={next} />
            <div className="space-y-2">
              <Label htmlFor="username">Username</Label>
              <Input id="username" name="username" type="text" required />
            </div>
            <div className="space-y-2">
              <Label htmlFor="password">Password</Label>
              <Input id="password" name="password" type="password" required />
            </div>
            <Button type="submit" className="w-full">
              Login
            </Button>
          </form>

          <p className="mt-4 text-center text-sm text-muted-foreground">
            No account yet?{" "}
            <Link href="/register" className="underline underline-offset-4">
              Register
            </Link>
          </p>
        </CardContent>
      </Card>
    </div>
  );
}
