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
        prompt = `Generate a highly tailored and persuasive cover letter for the ${job.jobTitle} position at ${job.companyName}. Align my skills, achievements, and experiences with the job description provided, ensuring the letter reflects the ${job.companyName} specific needs outlined in the job description. 

Personalize the introduction by referencing ${job.companyName} mission or culture, and ensure the closing is engaging and confident. Avoid generic language and instead highlight how my background makes me the ideal candidate.

Ensure all major achievements are quantified where possible to demonstrate clear, measurable impacts.

Here is the job description: ${job.jobDescription}

Here is my resume: ${resume}

Here is my current cover letter: ${coverLetter}`;

        break;
      case "resume":
        prompt = `Suggest detailed, section-specific modifications to my resume to better align with the role of ${job.jobTitle} at ${job.companyName}. Focus on identifying and emphasizing relevant skills, experiences, and achievements based on the job description provided.

Provide actionable feedback across the following areas:
1. **Professional Summary**: Refine the summary to highlight skills and experiences most relevant to the position. Emphasize adaptability, industry-specific expertise, and key qualifications mentioned in the job description.
2. **Work Experience**: Identify specific bullet points that should be modified to reflect the skills and responsibilities required for this role. Suggest how to highlight relevant achievements, quantify impact, and showcase transferable experiences, even if they come from a different industry.
3. **Skills Section**: Recommend the inclusion of job-specific keywords and technical or soft skills that align with the job description. Ensure the skills are formatted for optimal ATS (Applicant Tracking System) readability.
4. **Projects Section**: Highlight projects that demonstrate problem-solving abilities, technical expertise, or industry-relevant outcomes. Suggest how to frame projects to match the job’s focus, whether that’s on technical innovation, leadership, data analysis, etc.
5. **ATS Optimization**: Recommend specific keywords and formatting adjustments to ensure the resume passes ATS filters. Include any relevant certifications, methodologies, or tools mentioned in the job description.

Where applicable, suggest metrics or quantifiable results that can be added to better demonstrate the impact of my work.

Here is the job description: ${job.jobDescription}

Here is my resume: ${resume}
`;
        break;
      case "interview":
        prompt = `Generate a comprehensive list of potential interview questions for the ${job.jobTitle} position at ${job.companyName}. Focus on both technical and behavioral aspects that are relevant to the role. Include:

1. **Role-Specific Questions**: Tailor questions to the skills, technologies, and responsibilities outlined in the job description provided.
2. **Behavioral & Situational Questions**: Include questions that assess problem-solving, teamwork, leadership, and adaptability.
3. **Company-Focused Questions**: Suggest thoughtful questions I can ask during the interview that reflect ${job.companyName}'s mission, values, or industry focus.

For each question, provide strategic guidance on how I can answer effectively based on my background, emphasizing my skills, achievements, and experiences that align with the role. Where applicable, recommend using the STAR (Situation, Task, Action, Result) method for behavioral questions.

Here is the job description:
${job.jobDescription}
`;

        break;
      case "companyInsights":
        prompt = `Provide detailed insights into ${job.companyName} based on the job description for the ${job.jobTitle} position. Focus on the following areas:

1. **Company Overview & Mission:** Summarize the company's mission, values, and any unique aspects of their culture or approach within their industry.
2. **Industry Positioning:** Describe how ${job.companyName} is positioned within their industry. Highlight their competitive advantages, recent innovations, or trends they are addressing.
3. **Role Alignment:** Explain how the responsibilities and skills required for the ${job.jobTitle} position align with the company’s goals and strategic initiatives. Suggest ways I can demonstrate alignment with their mission and values in the interview.
4. **Interview Preparation:** Provide a list of thoughtful questions I can ask during the interview to demonstrate my knowledge of the company and interest in the role. For each question, briefly explain why it’s valuable to ask.
5. **Bonus:** If possible, suggest strategies or key talking points I can use during the interview to stand out as a strong candidate, based on the job description and company focus.

Here is the job description: ${job.jobDescription}`;

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
