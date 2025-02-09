"use client";

import Link from "next/link";
import { getJobPostings } from "@/utils/jobStorage";
import { getResume } from "@/utils/resumeStorage";
import { getCoverLetter } from "@/utils/coverLetterStorage";

export default function DashboardPage() {
  const jobs = getJobPostings();
  const resume = getResume();
  const coverLetter = getCoverLetter();

  return (
    <main className="min-h-screen bg-gray-50 p-8">
      <div className="max-w-5xl mx-auto bg-white shadow-lg rounded-xl p-6">
        <h1 className="text-3xl font-bold mb-4">
          Your Job Application Dashboard
        </h1>
        <p className="text-gray-600 mb-8">
          Manage your job descriptions, resume, and cover letters here. Generate
          tailored prompts to improve your application.
        </p>

        {/* Job Postings Overview */}
        <section className="mb-6">
          <h2 className="text-2xl font-semibold mb-2">Job Descriptions</h2>
          {jobs.length > 0 ? (
            jobs.map((job) => (
              <div
                key={job.id}
                className="p-4 border rounded-md mb-4 bg-gray-50">
                <h3 className="text-lg font-bold">
                  {job.jobTitle} at {job.companyName}
                </h3>
                <Link href={`/prompts?jobId=${job.id}`}>
                  <button className="mt-2 bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition">
                    Generate Prompts
                  </button>
                </Link>
              </div>
            ))
          ) : (
            <p className="text-gray-500">
              No job descriptions saved yet.{" "}
              <Link href="/jobs" className="text-blue-600 hover:underline">
                Add a job here
              </Link>
              .
            </p>
          )}
        </section>

        {/* Resume Overview */}
        <section className="mb-6">
          <h2 className="text-2xl font-semibold mb-2">Resume</h2>
          {resume ? (
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

        {/* Cover Letter Overview */}
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
