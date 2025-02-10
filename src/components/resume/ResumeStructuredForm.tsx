/**
 * ResumeStructuredForm.tsx
 *
 * An advanced resume input form that collects user data section-by-section (e.g., work experience, skills, education).
 * This structured input allows for more targeted AI prompt generation for specific resume sections.
 *
 * Key Features:
 * - Uses TypeScript with strict type definitions (`ResumeData`) for type safety.
 * - Automatically saves each section to localStorage, ensuring persistence across sessions.
 * - Provides an option to clear all structured resume data.
 * - Integrates with the PromptButtonGroup component to generate tailored prompts.
 */

"use client";

import { useState, useEffect } from "react";
import {
  saveStructuredResume,
  getStructuredResume,
  clearStructuredResume,
} from "@/utils/resumeStorage";
import { ResumeData } from "@/types/resume";

const initialResumeData: ResumeData = {
  contactInfo: "",
  professionalSummary: "",
  workExperience: "",
  education: "",
  skills: "",
  certifications: "",
  projects: "",
  volunteerExperience: "",
  languages: "",
};

export default function ResumeStructuredForm() {
  const [resumeData, setResumeData] = useState<ResumeData>(initialResumeData);

  // Load saved structured resume data
  useEffect(() => {
    const savedData = getStructuredResume();
    if (savedData) setResumeData(savedData);
  }, []);

  // Handle input changes
  const handleChange = (section: keyof ResumeData, value: string) => {
    const updatedResume = { ...resumeData, [section]: value };
    setResumeData(updatedResume);
    saveStructuredResume(updatedResume);
  };

  // Clear structured resume data
  const handleClear = () => {
    clearStructuredResume();
    setResumeData(initialResumeData);
  };

  return (
    <div className="p-6">
      <h2 className="text-xl font-bold mb-4">Advanced Resume Input</h2>
      <p className="text-gray-600 mb-4">
        Enter your resume details section-by-section for more targeted prompt
        generation.
      </p>

      {/* Form Fields */}
      {Object.entries(resumeData).map(([section, value]) => (
        <div key={section} className="mb-4">
          <label className="block text-sm font-medium text-gray-700 capitalize">
            {section.replace(/([A-Z])/g, " $1")}
          </label>
          <textarea
            value={value}
            onChange={(e) =>
              handleChange(section as keyof ResumeData, e.target.value)
            }
            className="w-full p-3 border rounded-md mt-1"
            rows={
              section === "workExperience" || section === "professionalSummary"
                ? 5
                : 3
            }
            placeholder={`Enter your ${section
              .replace(/([A-Z])/g, " $1")
              .toLowerCase()}...`}
          />
        </div>
      ))}

      <button
        onClick={handleClear}
        className="w-full bg-red-600 text-white py-2 rounded-lg hover:bg-red-700 transition">
        Clear Resume
      </button>
    </div>
  );
}
