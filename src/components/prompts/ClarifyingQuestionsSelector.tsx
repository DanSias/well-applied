/**
 * ClarifyingQuestionsSelector.tsx
 *
 * Component for selecting the number of clarifying questions the AI should ask before generating the final response.
 * Allows users to choose between 0 to 3 questions and visually highlights the active selection.
 *
 * Key Features:
 * - Provides radio buttons for selecting 0, 1, 2, or 3 clarifying questions.
 * - Passes the selected number of questions to the parent component for prompt generation.
 * - Limits selection to a maximum of three questions to keep the interaction concise.
 */

"use client";

interface ClarifyingQuestionsSelectorProps {
  numClarifyingQuestions: number;
  onClarifyingQuestionsChange: (num: number) => void;
}

export default function ClarifyingQuestionsSelector({
  numClarifyingQuestions,
  onClarifyingQuestionsChange,
}: ClarifyingQuestionsSelectorProps) {
  return (
    <div className="mb-6">
      <h2 className="text-xl font-semibold mb-2">Clarifying Questions</h2>
      <p className="text-gray-600 mb-2">
        Select how many clarifying questions you&apos;d like the AI to ask
        before generating the response.
      </p>
      <div className="flex space-x-4">
        {[0, 1, 2, 3].map((num) => (
          <label key={num} className="flex items-center space-x-2">
            <input
              type="radio"
              value={num}
              checked={numClarifyingQuestions === num}
              onChange={() => onClarifyingQuestionsChange(num)}
              className="w-5 h-5 text-blue-600"
            />
            <span>
              {num} {num === 1 ? "Question" : "Questions"}
            </span>
          </label>
        ))}
      </div>
    </div>
  );
}
