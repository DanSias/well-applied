/**
 * RefinementPhrases.tsx
 *
 * Component for displaying common refinement phrases that users can copy to improve AI-generated responses.
 * Phrases are categorized (e.g., Specificity, Tone & Style, Formatting, ATS Optimization) and provide copy-to-clipboard functionality.
 * Inline inputs are provided for phrases with placeholders (e.g., [industry/role]) to allow quick customization before copying.
 *
 * Key Features:
 * - Displays categorized refinement phrases to guide users in refining AI outputs.
 * - Detects placeholders in phrases and provides inline inputs for user customization.
 * - Includes a copy-to-clipboard button for each phrase with visual feedback.
 * - Provides an intuitive UI for navigating and selecting relevant refinement phrases.
 */

"use client";

import { useState } from "react";

const refinementCategories = [
  {
    category: "Specificity",
    phrases: [
      "Make this more specific to the [industry/role].",
      "Add more detail about my experience with [specific tool/skill].",
      "Focus on quantifiable achievements rather than general statements.",
    ],
  },
  {
    category: "Tone & Style",
    phrases: [
      "Make this more professional/formal.",
      "Simplify the language to be more concise.",
      "Use a more enthusiastic and engaging tone.",
    ],
  },
  {
    category: "Formatting",
    phrases: [
      "Format this into bullet points.",
      "Organize this chronologically.",
      "Ensure consistent formatting across all sections.",
    ],
  },
  {
    category: "ATS Optimization",
    phrases: [
      "Incorporate keywords from the job description for better ATS optimization.",
      "Ensure that important skills like [skill] are emphasized for ATS filters.",
      "Simplify formatting to ensure compatibility with ATS systems.",
    ],
  },
];

export default function RefinementPhrases() {
  const [copiedPhrase, setCopiedPhrase] = useState<string | null>(null);

  const handleCopy = (phrase: string) => {
    const filledPhrase = phrase.replace(/\[([^\]]+)\]/g, (_, placeholder) => {
      const inputElement = document.getElementById(
        placeholder
      ) as HTMLInputElement;
      return inputElement?.value || `[${placeholder}]`;
    });

    navigator.clipboard.writeText(filledPhrase);
    setCopiedPhrase(filledPhrase);
    setTimeout(() => setCopiedPhrase(null), 2000); // Reset copied state after 2 seconds
  };

  return (
    <div className="mt-8">
      <h2 className="text-xl font-semibold mb-4">Refine Your AI Results</h2>
      {refinementCategories.map((category) => (
        <div key={category.category} className="mb-6">
          <h3 className="text-lg font-bold mb-2">{category.category}</h3>
          <ul className="space-y-2">
            {category.phrases.map((phrase) => (
              <li
                key={phrase}
                className="flex flex-col md:flex-row items-start md:items-center justify-between p-3 bg-gray-50 border rounded-md hover:bg-gray-100 transition">
                <span className="text-gray-700">
                  {phrase.split(/(\[.*?\])/).map((part, index) => {
                    if (part.startsWith("[") && part.endsWith("]")) {
                      const placeholder = part.slice(1, -1);
                      return (
                        <input
                          key={index}
                          id={placeholder}
                          placeholder={placeholder}
                          className="border-b border-gray-400 mx-1 px-2 py-1 rounded-md focus:outline-none focus:border-blue-500"
                        />
                      );
                    }
                    return part;
                  })}
                </span>
                <button
                  onClick={() => handleCopy(phrase)}
                  className="mt-2 md:mt-0 md:ml-4 bg-blue-600 text-white px-3 py-1 rounded-lg hover:bg-blue-700 transition">
                  {copiedPhrase === phrase ? "Copied!" : "Copy"}
                </button>
              </li>
            ))}
          </ul>
        </div>
      ))}
    </div>
  );
}
