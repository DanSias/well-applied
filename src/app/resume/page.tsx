/**
 * ResumePage.tsx
 *
 * The resume input page with a tab interface allowing users to switch between simple full-resume upload and advanced, section-by-section input forms.
 * Provides guidance on the benefits of each approach to help users choose.
 *
 * Key Features:
 * - Tabbed interface for switching between `ResumeInputForm` and `ResumeStructuredForm`.
 * - Provides explanations to help users select the input method that best fits their needs.
 * - Displays prompt buttons for resume analysis.
 *
 * File Location: /src/app/resume/page.tsx
 */

"use client";

import { useState } from "react";
import {
  DocumentTextIcon,
  AdjustmentsHorizontalIcon,
} from "@heroicons/react/24/solid";
import ResumeInputForm from "@/components/resume/ResumeInputForm";
import ResumeStructuredForm from "@/components/resume/ResumeStructuredForm";
import PromptButtonGroup from "@/components/resume/PromptButtonGroup";

export default function ResumePage() {
  const [selectedTab, setSelectedTab] = useState<"simple" | "structured">(
    "structured"
  );

  return (
    <main className="min-h-screen bg-gray-50 p-8">
      <div className="max-w-3xl mx-auto">
        <h1 className="text-2xl font-bold mb-4 text-center">
          Optimize Your Resume for Every Opportunity
        </h1>

        <p className="text-gray-600 mb-6 text-center">
          Upload your resume in full or break it down section by section for
          more precise feedback and tailored prompts. The advanced input offers
          deeper insights to fine-tune each part of your resume.
        </p>
        <div className="sm:hidden mb-4">
          <label htmlFor="tabs" className="sr-only">
            Select Upload Method
          </label>
          <select
            id="tabs"
            value={selectedTab}
            onChange={(e) =>
              setSelectedTab(e.target.value as "simple" | "structured")
            }
            className="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5">
            <option value="simple">Simple Upload</option>
            <option value="structured">Advanced Upload</option>
          </select>
        </div>
      </div>

      <div className="max-w-4xl mx-auto bg-white shadow-lg rounded-xl p-6">
        {/* Mobile Dropdown */}

        {/* Desktop Tab Navigation */}
        <ul className="hidden text-sm font-medium text-center text-gray-500 rounded-lg shadow-sm sm:flex mb-6">
          <li className="w-full focus-within:z-10">
            <button
              onClick={() => setSelectedTab("simple")}
              className={`inline-flex items-center justify-center w-full p-4 ${
                selectedTab === "simple"
                  ? "text-gray-900 bg-gray-100"
                  : "bg-white hover:text-gray-700 hover:bg-gray-50"
              } border-r border-gray-200 rounded-s-lg focus:ring-4 focus:ring-blue-300`}>
              <DocumentTextIcon className="w-4 h-4 mr-2" />
              Simple Upload
            </button>
          </li>
          <li className="w-full focus-within:z-10">
            <button
              onClick={() => setSelectedTab("structured")}
              className={`inline-flex items-center justify-center w-full p-4 ${
                selectedTab === "structured"
                  ? "text-gray-900 bg-gray-100"
                  : "bg-white hover:text-gray-700 hover:bg-gray-50"
              } border-gray-200 rounded-e-lg focus:ring-4 focus:ring-blue-300`}>
              <AdjustmentsHorizontalIcon className="w-4 h-4 mr-2" />
              Advanced Upload
            </button>
          </li>
        </ul>
        <PromptButtonGroup resumeType={selectedTab} />

        {/* Conditional Rendering Based on Selected Tab */}
        {selectedTab === "simple" ? (
          <ResumeInputForm />
        ) : (
          <ResumeStructuredForm />
        )}

        {/* Prompt Buttons */}
      </div>
    </main>
  );
}
