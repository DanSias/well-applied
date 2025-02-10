/**
 * /src/app/dashboard/page.tsx
 *
 * The central hub for managing job applications, resumes, and cover letters.
 * Integrates components to display saved job descriptions, resumes, and cover letters with tailored prompts.
 *
 * Key Features:
 * - Displays saved job descriptions with prompt actions.
 * - Shows resume and cover letter status with options to view or edit.
 * - Encourages users to add missing information with clear call-to-actions.
 */

"use client";

import JobOverview from "@/components/dashboard/JobOverview";
import Link from "next/link";
import { getResume, getStructuredResume } from "@/utils/resumeStorage";
import { getCoverLetter } from "@/utils/coverLetterStorage";

export default function DashboardPage() {
  const simpleResume = getResume();
  const structuredResume = getStructuredResume();
  const hasResume = simpleResume || structuredResume;
  const coverLetter = getCoverLetter();

  return (
    <main className="min-h-screen bg-gray-50 p-8">
      <div className="max-w-3xl mx-auto text-center mb-6">
        <h1 className="text-3xl font-bold mb-2">
          Your Job Application Dashboard
        </h1>
        <p className="text-gray-600">
          Manage your job descriptions, resumes, and cover letters here.
          Generate tailored prompts to improve your application.
        </p>
      </div>

      <div className="max-w-5xl mx-auto bg-white shadow-lg rounded-xl p-6">
        {/* Job Overview Section */}
        <JobOverview />

        {/* Resume Overview Section */}
        <section className="mb-6">
          <h2 className="text-2xl font-semibold mb-2">Resume</h2>
          {hasResume ? (
            <Link href="/resume">
              <button className="bg-green-600 text-white px-4 py-2 rounded-lg hover:bg-green-700 transition">
                View/Edit Resume
              </button>
            </Link>
          ) : (
            <p className="text-gray-500">
              No resume uploaded yet.{" "}
              <Link href="/resume" className="text-green-600 hover:underline">
                Upload your resume
              </Link>
              .
            </p>
          )}
        </section>

        {/* Cover Letter Overview Section */}
        <section className="mb-6">
          <h2 className="text-2xl font-semibold mb-2">Cover Letter</h2>
          {coverLetter ? (
            <Link href="/cover-letter">
              <button className="bg-purple-600 text-white px-4 py-2 rounded-lg hover:bg-purple-700 transition">
                View/Edit Cover Letter
              </button>
            </Link>
          ) : (
            <p className="text-gray-500">
              No cover letter uploaded yet.{" "}
              <Link
                href="/cover-letter"
                className="text-purple-600 hover:underline">
                Upload your cover letter
              </Link>
              .
            </p>
          )}
        </section>
      </div>
    </main>
  );
}
