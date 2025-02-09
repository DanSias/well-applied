/**
 * GeneratedPromptDisplay.tsx
 *
 * Component for displaying the generated AI prompt and providing copy-to-clipboard functionality.
 * Allows users to view and easily copy the generated prompt to use in AI tools.
 *
 * Key Features:
 * - Displays the generated prompt in a read-only text area.
 * - Includes a copy-to-clipboard button with visual feedback.
 * - Provides clear UI feedback when the prompt is successfully copied.
 */

"use client";

import { useState } from "react";

interface GeneratedPromptDisplayProps {
  generatedPrompt: string;
}

export default function GeneratedPromptDisplay({
  generatedPrompt,
}: GeneratedPromptDisplayProps) {
  const [copied, setCopied] = useState(false);

  const handleCopy = () => {
    navigator.clipboard.writeText(generatedPrompt);
    setCopied(true);
    setTimeout(() => setCopied(false), 2000); // Reset copied state after 2 seconds
  };

  return (
    <div className="mt-6">
      <h2 className="text-xl font-semibold mb-2">Generated Prompt</h2>
      <textarea
        value={generatedPrompt}
        readOnly
        className="w-full p-4 border rounded-md bg-gray-100"
        rows={8}
      />
      <button
        onClick={handleCopy}
        className="mt-4 w-full bg-green-600 text-white py-2 rounded-lg hover:bg-green-700 transition">
        {copied ? "Copied!" : "Copy Prompt"}
      </button>
    </div>
  );
}
