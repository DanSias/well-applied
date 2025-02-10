/**
 * /src/components/jobs/JobForm.tsx
 *
 * A form component for adding new job postings. Used within a modal on the Prompts Page and Jobs Page.
 *
 * Key Features:
 * - Collects job title, company name, and job description.
 * - Saves the new job to localStorage and triggers a callback when submitted.
 * - Displays a toast notification when a job is successfully added.
 * - Clears the form fields after submission.
 */

"use client";

import { useState } from "react";
import { saveJobPosting } from "@/utils/jobStorage";
import { v4 as uuidv4 } from "uuid";
import { toast } from "react-hot-toast";

interface JobFormProps {
  onJobAdded?: () => void;
}

export default function JobForm({ onJobAdded }: JobFormProps) {
  const [jobTitle, setJobTitle] = useState("");
  const [companyName, setCompanyName] = useState("");
  const [jobDescription, setJobDescription] = useState("");

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();

    const newJob = {
      id: uuidv4(),
      jobTitle,
      companyName,
      jobDescription,
      createdAt: new Date().toISOString(),
    };

    saveJobPosting(newJob);
    toast.success("Job added successfully!");

    // Clear form fields after submission
    setJobTitle("");
    setCompanyName("");
    setJobDescription("");

    if (onJobAdded) onJobAdded();
  };

  return (
    <form onSubmit={handleSubmit} className="space-y-4">
      <div>
        <label className="block text-sm font-medium text-gray-700">
          Job Title
        </label>
        <input
          type="text"
          value={jobTitle}
          onChange={(e) => setJobTitle(e.target.value)}
          className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent text-gray-700 placeholder-gray-400 resize-none"
          required
        />
      </div>

      <div>
        <label className="block text-sm font-medium text-gray-700">
          Company Name
        </label>
        <input
          type="text"
          value={companyName}
          onChange={(e) => setCompanyName(e.target.value)}
          className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent text-gray-700 placeholder-gray-400 resize-none"
          required
        />
      </div>

      <div>
        <label className="block text-sm font-medium text-gray-700">
          Job Description
        </label>
        <textarea
          value={jobDescription}
          onChange={(e) => setJobDescription(e.target.value)}
          className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent text-gray-700 placeholder-gray-400 resize-none"
          rows={4}
          required></textarea>
      </div>

      <button
        type="submit"
        className="w-full bg-green-600 text-white py-2 rounded-lg hover:bg-green-700 transition">
        Save Job
      </button>
    </form>
  );
}
