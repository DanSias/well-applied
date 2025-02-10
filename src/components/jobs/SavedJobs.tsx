/**
 * SavedJobs.tsx
 *
 * A component for displaying saved job postings with options to generate and copy prompts for job analysis and interview preparation.
 * Now includes split buttons with dropdowns to copy prompts and open specific LLMs like ChatGPT, Claude, and Gemini.
 *
 * Key Features:
 * - Lists saved jobs with job title, company name, and description.
 * - Provides split buttons to generate "Job Analysis" and "Interview Prep" prompts.
 * - Copies prompts to clipboard and opens LLMs in a new tab.
 * - Displays toast notifications upon copying.
 *
 * File Location: /src/components/jobs/SavedJobs.tsx
 */

"use client";

import { JobPosting } from "@/types/jobPosting";
import { toast } from "react-hot-toast";
import { Fragment } from "react";
import { Menu, Transition } from "@headlessui/react";
import { ChevronDownIcon } from "@heroicons/react/20/solid";

interface SavedJobsProps {
  jobs: JobPosting[];
}

export default function SavedJobs({ jobs }: SavedJobsProps) {
  const copyToClipboard = (prompt: string, promptType: string) => {
    navigator.clipboard.writeText(prompt);
    toast.success(`${promptType} prompt copied to clipboard!`);
  };

  const copyAndOpenLLM = (prompt: string, llm: string) => {
    navigator.clipboard.writeText(prompt);
    let url = "";

    switch (llm) {
      case "ChatGPT":
        url = "https://chat.openai.com/";
        break;
      case "Claude":
        url = "https://claude.ai/";
        break;
      case "Gemini":
        url = "https://gemini.google.com/";
        break;
      default:
        url = "https://chat.openai.com/";
    }

    window.open(url, "_blank");
    toast.success(`Prompt copied and opened in ${llm}!`);
  };

  const generatePrompt = (
    job: JobPosting,
    promptType: "analysis" | "interview"
  ) => {
    if (promptType === "analysis") {
      return `Provide a comprehensive analysis of the following job description. Identify the top keywords and skills to include in my application, summarize the key responsibilities and expectations for this role, and suggest any company or industry research I should conduct to better tailor my application and interview approach.\n\nJob Title: ${job.jobTitle}\nCompany: ${job.companyName}\nDescription: ${job.jobDescription}`;
    } else {
      return `Based on the following job description, list the most likely technical and behavioral interview questions I might be asked. Also, provide thoughtful questions I can ask the interviewer to demonstrate my interest in the role and company. Finally, outline any industry or company-specific topics I should be familiar with to excel during the interview.\n\nJob Title: ${job.jobTitle}\nCompany: ${job.companyName}\nDescription: ${job.jobDescription}`;
    }
  };

  return (
    <div>
      {jobs.length > 0 ? (
        jobs.map((job) => (
          <div
            key={job.id}
            className="p-4 border rounded-md mb-4 bg-gray-50 hover:bg-gray-100 transition grid grid-cols-4 gap-4">
            {/* Left Column: Title, Company, Description */}
            <div className="col-span-3">
              <h3 className="text-lg font-bold">
                {job.jobTitle} at {job.companyName}
              </h3>
              <p className="text-gray-600 mt-2">
                {job.jobDescription.slice(0, 150)}...
              </p>
            </div>

            {/* Right Column: Split Buttons */}
            <div className="col-span-1 flex flex-col space-y-2">
              {[
                {
                  label: "Job Analysis",
                  type: "analysis",
                  color: "bg-blue-600",
                },
                {
                  label: "Interview Prep",
                  type: "interview",
                  color: "bg-purple-600",
                },
              ].map(({ label, type, color }) => (
                <div
                  key={label}
                  className="relative inline-flex shadow-sm rounded-lg">
                  {/* Main Button */}
                  <button
                    onClick={() =>
                      copyToClipboard(
                        generatePrompt(job, type as "analysis" | "interview"),
                        label
                      )
                    }
                    className={`${color} text-white py-2 px-4 rounded-l-lg hover:opacity-90 transition w-full`}>
                    {label}
                  </button>

                  {/* Dropdown Toggle */}
                  <Menu as="div" className="relative">
                    <Menu.Button
                      className={`${color} text-white py-2 px-3 rounded-r-lg hover:opacity-90 transition h-full`}>
                      <ChevronDownIcon className="w-5 h-5" aria-hidden="true" />
                    </Menu.Button>

                    <Transition
                      as={Fragment}
                      enter="transition ease-out duration-100"
                      enterFrom="transform opacity-0 scale-95"
                      enterTo="transform opacity-100 scale-100"
                      leave="transition ease-in duration-75"
                      leaveFrom="transform opacity-100 scale-100"
                      leaveTo="transform opacity-0 scale-95">
                      <Menu.Items className="absolute right-0 z-10 mt-2 w-48 origin-top-right bg-white border border-gray-200 divide-y divide-gray-100 rounded-md shadow-lg focus:outline-none">
                        {["ChatGPT", "Claude", "Gemini"].map((llm) => (
                          <Menu.Item key={llm}>
                            {({ active }) => (
                              <button
                                onClick={() =>
                                  copyAndOpenLLM(
                                    generatePrompt(
                                      job,
                                      type as "analysis" | "interview"
                                    ),
                                    llm
                                  )
                                }
                                className={`${
                                  active ? "bg-gray-100" : ""
                                } flex items-center w-full px-4 py-2 text-sm text-gray-700`}>
                                Copy & Open {llm}
                              </button>
                            )}
                          </Menu.Item>
                        ))}
                      </Menu.Items>
                    </Transition>
                  </Menu>
                </div>
              ))}
            </div>
          </div>
        ))
      ) : (
        <p className="text-gray-500">No saved job postings yet.</p>
      )}
    </div>
  );
}
