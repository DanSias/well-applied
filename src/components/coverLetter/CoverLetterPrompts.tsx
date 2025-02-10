/**
 * A reusable component for generating and copying prompts to improve cover letters.
 * Provides comprehensive feedback and generates an updated version of the cover letter based on recommendations.
 *
 * Key Features:
 * - Analyzes cover letter content, structure, tone, and ATS compatibility.
 * - Generates a fully revised cover letter incorporating the suggested improvements.
 * - Retrieves the cover letter from localStorage and copies the generated prompt to the clipboard with a toast notification.
 * - Offers a split button dropdown to copy and open the prompt in different LLMs (ChatGPT, Claude, Gemini).
 */

"use client";

import { useState, useEffect } from "react";
import { getCoverLetter } from "@/utils";
import { toast } from "react-hot-toast";
import { Menu, Transition } from "@headlessui/react";
import { PencilSquareIcon, ChevronDownIcon } from "@heroicons/react/24/solid";

export default function CoverLetterPrompts() {
  const [coverLetterData, setCoverLetterData] = useState<string>("");

  useEffect(() => {
    const savedCoverLetter = getCoverLetter();
    setCoverLetterData(savedCoverLetter);
  }, []);

  // Generate the comprehensive prompt
  const generatePrompt = () => {
    return `Conduct a comprehensive review of the following cover letter and provide actionable feedback across key areas.

1. **Content & Structure**: Suggest improvements to the letter's flow, clarity, and organization. Ensure the introduction is **personalized to the company's mission or recent achievements** and the closing is **assertive** with a clear call-to-action.
2. **Achievements & Skills**: Highlight key accomplishments and skills, suggesting areas where the candidate can better showcase their qualifications. **Identify any missing metrics** and recommend specific ways to quantify achievements.
3. **Tone & Persuasion**: Ensure the tone is professional, confident, and persuasive. Recommend action verbs and language to make the letter more compelling. **Encourage a balance of confidence and adaptability**.
4. **ATS Optimization**: Check for industry-specific keywords and formatting issues that may hinder ATS readability. Suggest additional **relevant keywords based on common job descriptions** in the field.

---

**Updated Cover Letter:**  
Based on the recommendations above, provide a fully revised cover letter that incorporates all suggested improvements. Ensure the letter is **personalized to the company**, emphasizes the candidate’s **unique value proposition**, and uses a **professional and engaging tone** while highlighting achievements and skills.

Here is the cover letter:

${coverLetterData}`;
  };

  // Copy to clipboard and optionally open in a new tab
  const handleCopyAndOpen = (platformUrl?: string) => {
    if (!coverLetterData) {
      toast.error(
        "No cover letter data found. Please upload your cover letter."
      );
      return;
    }

    const prompt = generatePrompt();
    navigator.clipboard.writeText(prompt);
    toast.success("Prompt copied to clipboard!");

    if (platformUrl) {
      window.open(platformUrl, "_blank");
    }
  };

  return (
    <div className="mb-6 flex justify-center">
      <Menu as="div" className="relative inline-block text-left">
        <div className="flex">
          <button
            className="flex items-center bg-indigo-600 text-white py-2 px-4 rounded-l-lg hover:bg-indigo-700 transition"
            onClick={() => handleCopyAndOpen()}>
            <PencilSquareIcon className="w-5 h-5 mr-2" />
            Enhance Cover Letter with AI
          </button>
          <Menu.Button className="bg-indigo-600 text-white py-2 px-2 rounded-r-lg hover:bg-indigo-700 transition">
            <ChevronDownIcon className="w-5 h-5" />
          </Menu.Button>
        </div>
        <Transition
          enter="transition ease-out duration-100"
          enterFrom="transform opacity-0 scale-95"
          enterTo="transform opacity-100 scale-100"
          leave="transition ease-in duration-75"
          leaveFrom="transform opacity-100 scale-100"
          leaveTo="transform opacity-0 scale-95">
          <Menu.Items className="absolute right-0 mt-2 w-56 origin-top-right bg-white divide-y divide-gray-100 rounded-md shadow-lg ring-1 ring-black ring-opacity-5 focus:outline-none">
            <div className="px-1 py-1">
              <Menu.Item>
                <button
                  onClick={() => handleCopyAndOpen("https://chat.openai.com/")}
                  className="group flex rounded-md items-center w-full px-2 py-2 text-sm text-gray-900 hover:bg-indigo-500 hover:text-white">
                  Copy & Open ChatGPT
                </button>
              </Menu.Item>
              <Menu.Item>
                <button
                  onClick={() => handleCopyAndOpen("https://claude.ai/")}
                  className="group flex rounded-md items-center w-full px-2 py-2 text-sm text-gray-900 hover:bg-indigo-500 hover:text-white">
                  Copy & Open Claude
                </button>
              </Menu.Item>
              <Menu.Item>
                <button
                  onClick={() =>
                    handleCopyAndOpen("https://gemini.google.com/")
                  }
                  className="group flex rounded-md items-center w-full px-2 py-2 text-sm text-gray-900 hover:bg-indigo-500 hover:text-white">
                  Copy & Open Gemini
                </button>
              </Menu.Item>
            </div>
          </Menu.Items>
        </Transition>
      </Menu>
    </div>
  );
}
