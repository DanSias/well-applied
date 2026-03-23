import Link from "next/link";

export default function MarketingHomePage() {
  return (
    <main className="flex min-h-screen flex-col items-center justify-center bg-white px-6">
      <div className="max-w-xl text-center">
        <h1 className="text-4xl font-bold tracking-tight text-zinc-900">
          Well Applied
        </h1>
        <p className="mt-4 text-lg text-zinc-500">
          Track your job applications and generate tailored resumes with AI.
        </p>
        <div className="mt-8 flex justify-center gap-4">
          <Link
            href="/login"
            className="rounded-md bg-zinc-900 px-5 py-2.5 text-sm font-medium text-white hover:bg-zinc-700"
          >
            Log in
          </Link>
          <Link
            href="/signup"
            className="rounded-md border border-zinc-200 px-5 py-2.5 text-sm font-medium text-zinc-700 hover:bg-zinc-50"
          >
            Sign up
          </Link>
        </div>
      </div>
    </main>
  );
}
