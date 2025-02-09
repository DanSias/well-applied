import Link from "next/link";
import { appFeatures } from "@/data/appFeatures";

export default function Home() {
  return (
    <main className="min-h-screen bg-gray-50 flex flex-col items-center justify-center p-8">
      <div className="max-w-4xl w-full bg-white shadow-lg rounded-xl p-6">
        <h1 className="text-3xl font-bold text-center mb-4">
          Welcome to Well Applied
        </h1>
        <p className="text-gray-600 text-center mb-8">
          Simplify your job search with AI-powered prompts for tailoring
          resumes, researching companies, and preparing for interviews.
        </p>

        <div className="grid grid-cols-1 sm:grid-cols-2 gap-6">
          {appFeatures.map((item) => (
            <Link href={`/${item.slug}`} key={item.slug}>
              <div
                className={`p-4 border rounded-lg shadow-sm hover:shadow-md ${item.colorClass} transition cursor-pointer`}>
                <h2 className="text-xl font-semibold mb-2">{item.title}</h2>
                <p className="text-gray-500">{item.description}</p>
              </div>
            </Link>
          ))}
        </div>
      </div>
    </main>
  );
}
