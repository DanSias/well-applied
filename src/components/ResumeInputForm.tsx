/**
 * ResumeInputForm.tsx
 *
 * A simple resume upload component that allows users to paste their entire resume into a single text field.
 * The input is automatically saved to localStorage and can be retrieved for generating tailored AI prompts.
 *
 * Key Features:
 * - Uses React hooks (`useState`, `useEffect`) for managing state and side effects.
 * - Integrates with localStorage utilities to persist resume data.
 * - Provides an option to clear the saved resume.
 */

"use client";

import { useEffect, useState } from "react";
import { saveResume, getResume } from "@/utils";

const ResumeInputForm = () => {
  const [resumeText, setResumeText] = useState("");

  // Load the resume from localStorage when the component mounts
  useEffect(() => {
    const savedResume = getResume();
    setResumeText(savedResume);
  }, []);

  // Save the resume to localStorage whenever it changes
  const handleResumeChange = (e: React.ChangeEvent<HTMLTextAreaElement>) => {
    const updatedResume = e.target.value;
    setResumeText(updatedResume);
    saveResume(updatedResume);
  };

  return (
    <div className="h-full pb-28">
      <textarea
        value={resumeText}
        onChange={handleResumeChange}
        className="w-full p-4 border rounded-md h-full"
        placeholder="Paste your resume here..."
      />
    </div>
  );
};

export default ResumeInputForm;
