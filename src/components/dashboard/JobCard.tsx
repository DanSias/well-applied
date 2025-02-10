/**
 * JobCard.tsx
 *
 * Displays individual job details and provides buttons to generate tailored prompts.
 * Handles prompt generation for cover letters, resumes, interview prep, and company insights.
 *
 * Key Features:
 * - Shows job title, company, and a preview of the job description.
 * - Provides prompt buttons with visual feedback using react-hot-toast.
 * - Uses saved resume and cover letter data to generate customized prompts.
 */

"use client";

import { JobPosting } from "@/types/jobPosting";
import {
  PencilSquareIcon,
  DocumentTextIcon,
  ChatBubbleLeftRightIcon,
  BuildingOfficeIcon,
} from "@heroicons/react/24/solid";
import { toast } from "react-hot-toast";

interface JobCardProps {
  job: JobPosting;
  resume: string;
  coverLetter: string;
}

export default function JobCard({ job, resume, coverLetter }: JobCardProps) {
  const handlePrompt = (
    type: "coverLetter" | "resume" | "interview" | "companyInsights"
  ) => {
    let prompt = "";
    switch (type) {
      case "coverLetter":
        prompt = `Generate a tailored cover letter for the position of ${job.jobTitle} at ${job.companyName}. Align my skills and experiences with the job description provided.\n\nHere is my current cover letter: ${coverLetter}`;
        break;
      case "resume":
        prompt = `Suggest modifications to my resume to better align with the role of ${job.jobTitle} at ${job.companyName}. Highlight relevant skills and experiences based on the job description.\n\nHere is my resume: ${resume}`;
        break;
      case "interview":
        prompt = `Generate common interview questions for the ${job.jobTitle} position at ${job.companyName} and suggest strategies to answer them based on my background.`;
        break;
      case "companyInsights":
        prompt = `Provide insights into ${job.companyName} based on the job description for the ${job.jobTitle} position. Suggest thoughtful questions I can ask during the interview.`;
        break;
      default:
        prompt = "";
    }

    navigator.clipboard.writeText(prompt);
    toast.success(`${type.replace(/([A-Z])/g, " $1")} prompt copied!`, {
      icon: "📋",
    });
  };

  return (
    <div className="p-4 border rounded-md mb-4 bg-gray-50 flex justify-between items-start">
      <div className="w-3/4 pr-4">
        <h3 className="text-lg font-bold">
          {job.jobTitle} at {job.companyName}
        </h3>
        <p className="text-gray-600 mt-1">
          {job.jobDescription.slice(0, 150)}...
        </p>
      </div>
      <div className="w-1/4 grid grid-cols-2 gap-2">
        <button
          onClick={() => handlePrompt("coverLetter")}
          className="border border-indigo-600 text-indigo-600 px-1 py-1 text-sm rounded-lg hover:bg-indigo-600 hover:text-white transition flex items-center justify-center">
          <PencilSquareIcon className="w-4 h-4 mr-1" /> Cover
        </button>
        <button
          onClick={() => handlePrompt("resume")}
          className="border border-green-600 text-green-600 px-1 py-1 text-sm rounded-lg hover:bg-green-600 hover:text-white transition flex items-center justify-center">
          <DocumentTextIcon className="w-4 h-4 mr-1" /> Resume
        </button>
        <button
          onClick={() => handlePrompt("interview")}
          className="border border-blue-600 text-blue-600 px-1 py-1 text-sm rounded-lg hover:bg-blue-600 hover:text-white transition flex items-center justify-center">
          <ChatBubbleLeftRightIcon className="w-4 h-4 mr-1" /> Interview
        </button>
        <button
          onClick={() => handlePrompt("companyInsights")}
          className="border border-purple-600 text-purple-600 px-1 py-1 text-sm rounded-lg hover:bg-purple-600 hover:text-white transition flex items-center justify-center">
          <BuildingOfficeIcon className="w-4 h-4 mr-1" /> Insights
        </button>
      </div>
    </div>
  );
}
