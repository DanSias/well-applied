"use client";

import { useState, useEffect } from "react";
import { saveCoverLetter, getCoverLetter, clearCoverLetter } from "@/utils";

export default function CoverLetterInputForm() {
  const [coverLetterText, setCoverLetterText] = useState("");

  // Load the saved cover letter when the component mounts
  useEffect(() => {
    const savedCoverLetter = getCoverLetter();
    setCoverLetterText(savedCoverLetter);
  }, []);

  // Handle cover letter input changes
  const handleCoverLetterChange = (
    e: React.ChangeEvent<HTMLTextAreaElement>
  ) => {
    const updatedCoverLetter = e.target.value;
    setCoverLetterText(updatedCoverLetter);
    saveCoverLetter(updatedCoverLetter);
  };

  // Clear the saved cover letter
  const handleClearCoverLetter = () => {
    clearCoverLetter();
    setCoverLetterText("");
  };

  return (
    <div>
      <textarea
        value={coverLetterText}
        onChange={handleCoverLetterChange}
        className="w-full p-4 border rounded-md min-h-[20rem] max-h-[70vh] overflow-auto"
        placeholder="Paste your existing cover letter here..."
      />
      <button
        onClick={handleClearCoverLetter}
        className="mt-4 w-full bg-red-600 text-white py-2 rounded-lg hover:bg-red-700 transition">
        Clear Cover Letter
      </button>
    </div>
  );
}
