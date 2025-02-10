/**
 * PromptButtonGroup.tsx
 *
 * A reusable component for generating and copying prompts based on the user's uploaded resume.
 * Supports both simple and structured resume inputs by pulling data from localStorage or receiving formatted resume data as a prop.
 *
 * Key Features:
 * - Generates prompts for Content Optimization, Skills & Role Alignment, and Career Growth.
 * - Automatically retrieves resume data from localStorage based on resume type or uses provided formatted data.
 * - Copies the generated prompt to the clipboard with a toast notification.
 *
 * Props:
 * - `resumeType`: 'simple' | 'structured' - Determines which resume data to pull from localStorage.
 * - `formattedResume`?: string - Optional prop for directly providing formatted resume data (used in structured input).
 *
 * File Location: /src/components/PromptButtonGroup.tsx
 */

"use client";

import { useState, useEffect } from "react";
import { toast } from "react-hot-toast";
import {
  DocumentTextIcon,
  BriefcaseIcon,
  ArrowTrendingUpIcon,
} from "@heroicons/react/24/solid";

interface PromptButtonGroupProps {
  resumeType: "simple" | "structured";
  formattedResume?: string;
}

export default function PromptButtonGroup({
  resumeType,
  formattedResume,
}: PromptButtonGroupProps) {
  const [resumeData, setResumeData] = useState<string>("");

  // Helper function to format section headings
  const formatSectionHeading = (section: string) => {
    return section
      .replace(/([A-Z])/g, " $1") // Add space before capital letters
      .replace(/^./, (str) => str.toUpperCase()); // Capitalize first letter
  };

  // Fetch resume data from localStorage or use provided formatted resume
  useEffect(() => {
    if (resumeType === "simple") {
      const simpleResume = localStorage.getItem("userResume");
      setResumeData(simpleResume || "");
    } else if (formattedResume) {
      setResumeData(formattedResume);
    } else {
      const structuredResume = JSON.parse(
        localStorage.getItem("structuredResume") || "{}"
      ) as Record<string, string>;
      const formattedStructuredResume = Object.entries(structuredResume)
        .filter(
          ([, content]) => typeof content === "string" && content.trim() !== ""
        ) // Filter out empty fields
        .map(
          ([section, content]) => `${formatSectionHeading(section)}\n${content}`
        )
        .join("\n\n");
      setResumeData(formattedStructuredResume);
    }
  }, [resumeType, formattedResume]);

  // Generate and copy prompt
  const generateAndCopyPrompt = (
    promptType: "content" | "skills" | "career"
  ) => {
    if (!resumeData) {
      toast.error("No resume data found. Please upload your resume.");
      return;
    }

    let prompt = "";

    switch (promptType) {
      case "content":
        prompt = `Review the following resume and provide **detailed, actionable feedback** to improve its **content, structure, and layout**.
          
          1. **Content**: Identify redundant phrases, suggest more concise wording, and highlight areas where achievements can be **quantified** (e.g., percentages, dollar amounts, time savings). Recommend soft skills that are **implicitly demonstrated** and suggest where they could be highlighted.
          
          2. **Structure**: Offer suggestions to **reorganize sections** for better flow and clarity. Evaluate whether skills, education, and projects are grouped logically and suggest better formatting for readability (e.g., bullet alignment, section ordering).
          
          3. **Tone & Language**: Ensure the tone remains **professional and confident** without sounding repetitive. Recommend action verbs to enhance the descriptions.
          
          4. **ATS Optimization**: Suggest keywords that improve **Applicant Tracking System (ATS)** compatibility, and recommend removing or revising content that might hinder ATS readability.
          
          Here is the resume:
          \n\n${resumeData}`;
        break;

      case "skills":
        prompt = `Analyze the following resume and extract the most relevant **skills** and **keywords** to optimize it for **Applicant Tracking Systems (ATS)**.
          
          1. **Keyword Extraction**: Identify and list the most important **technical** and **soft skills** relevant to the candidate's field. Ensure the resume includes **industry-standard terminology** for better ATS compatibility.
          
          2. **Missing Skills**: Suggest any **additional skills** or **certifications** that could strengthen the candidate's qualifications based on the job roles their experience aligns with.
          
          3. **Job Title Suggestions**: Based on the candidate’s experience and skills, recommend **specific job titles** or **roles** they would be a strong fit for.
          
          4. **ATS Optimization**: Highlight any sections or formatting elements that could **hinder ATS readability** (e.g., special characters, inconsistent formatting).
          
          Here is the resume:
          \n\n${resumeData}`;
        break;

      case "career":
        prompt = `Review the following resume and provide **personalized, prioritized career guidance** to support the candidate’s professional growth.
          
          1. **Ranked Career Paths**: Based on the candidate's current skills and experience, suggest **3-5 career paths** that are best suited for their background. Prioritize roles based on **market demand**, **earning potential**, and **alignment with existing strengths**.
          
          2. **Skill Gap Analysis**: Identify **specific skills** or **technical proficiencies** that the candidate needs to develop for these prioritized roles. Avoid suggesting skills unrelated to the candidate’s background or interests (e.g., mobile development if not relevant).
          
          3. **Short-Term Action Plan**: Provide **immediate, actionable steps** the candidate can take within the next **3-6 months** to advance in their career. This might include specific **online courses, certifications, open-source contributions,** or **networking strategies**.
          
          4. **Certifications & Professional Development**: Recommend **industry-recognized certifications** or **training programs** that would enhance the candidate's qualifications for the prioritized career paths.
          
          5. **Industry-Specific Tailoring**: Suggest ways to **tailor the resume** for different industries (e.g., **tech**, **finance**, **healthcare**). Include relevant **keywords**, **skills**, and **achievements** that should be highlighted for each industry.
          
          6. **Leadership & Soft Skills**: Identify **soft skills** (e.g., leadership, cross-functional collaboration, strategic thinking) that the candidate should emphasize, especially if targeting **management** or **executive** roles.
          
          **Important**: Avoid generic suggestions and focus on **practical, personalized advice** that aligns with the candidate's experience and professional goals.
          
          Here is the resume:
          \n\n${resumeData}`;
        break;

      default:
        return;
    }

    navigator.clipboard.writeText(prompt);
    toast.success("Prompt copied to clipboard!");
  };

  return (
    <div className="mt-4 grid grid-cols-3 gap-4">
      <button
        className="flex items-center bg-green-600 text-white py-2 px-4 rounded-lg hover:bg-green-700 transition"
        onClick={() => generateAndCopyPrompt("content")}>
        <DocumentTextIcon className="w-5 h-5 mr-2" />
        Optimize
      </button>

      <button
        className="flex items-center bg-blue-600 text-white py-2 px-4 rounded-lg hover:bg-blue-700 transition"
        onClick={() => generateAndCopyPrompt("skills")}>
        <BriefcaseIcon className="w-5 h-5 mr-2" />
        Align Skills
      </button>

      <button
        className="flex items-center bg-purple-600 text-white py-2 px-4 rounded-lg hover:bg-purple-700 transition"
        onClick={() => generateAndCopyPrompt("career")}>
        <ArrowTrendingUpIcon className="w-5 h-5 mr-2" />
        Career Growth
      </button>
    </div>
  );
}
