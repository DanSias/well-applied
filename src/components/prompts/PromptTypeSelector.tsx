/**
 * PromptTypeSelector.tsx
 *
 * Component for selecting the type of prompt to generate: Resume, Cover Letter, or Interview Prep.
 * Allows users to switch between different prompt types and visually highlights the active selection.
 *
 * Key Features:
 * - Provides buttons for selecting the desired prompt type.
 * - Passes the selected prompt type to the parent component for prompt generation.
 * - Visually distinguishes the active selection with dynamic styling.
 */

"use client";

interface PromptTypeSelectorProps {
  selectedPromptType: "resume" | "coverLetter" | "interview";
  onPromptTypeChange: (type: "resume" | "coverLetter" | "interview") => void;
}

export default function PromptTypeSelector({
  selectedPromptType,
  onPromptTypeChange,
}: PromptTypeSelectorProps) {
  return (
    <div className="mb-6">
      <h2 className="text-xl font-semibold mb-2">Select Prompt Type</h2>
      <div className="flex space-x-4">
        <button
          className={`px-4 py-2 rounded-md transition ${
            selectedPromptType === "resume"
              ? "bg-blue-600 text-white"
              : "bg-gray-200 hover:bg-blue-100"
          }`}
          onClick={() => onPromptTypeChange("resume")}>
          Resume
        </button>
        <button
          className={`px-4 py-2 rounded-md transition ${
            selectedPromptType === "coverLetter"
              ? "bg-blue-600 text-white"
              : "bg-gray-200 hover:bg-blue-100"
          }`}
          onClick={() => onPromptTypeChange("coverLetter")}>
          Cover Letter
        </button>
        <button
          className={`px-4 py-2 rounded-md transition ${
            selectedPromptType === "interview"
              ? "bg-blue-600 text-white"
              : "bg-gray-200 hover:bg-blue-100"
          }`}
          onClick={() => onPromptTypeChange("interview")}>
          Interview Prep
        </button>
      </div>
    </div>
  );
}
