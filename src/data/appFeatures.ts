// /src/data/appFeatures.ts

export interface AppFeature {
  title: string;
  slug: string;
  description: string;
  colorClass: string; // For unique hover colors
}

export const appFeatures: AppFeature[] = [
  {
    title: "Resume & Cover Letter",
    slug: "resume",
    description:
      "Generate prompts to tailor your resume and write compelling cover letters.",
    colorClass: "hover:bg-blue-50",
  },
  {
    title: "Company Research",
    slug: "company-research",
    description:
      "Get insights into companies to better align your application and interview prep.",
    colorClass: "hover:bg-green-50",
  },
  {
    title: "Interview Prep",
    slug: "interview-prep",
    description:
      "Prepare for interviews with AI-generated practice questions and tips.",
    colorClass: "hover:bg-yellow-50",
  },
  {
    title: "Saved Prompts",
    slug: "saved",
    description: "View and manage your previously generated prompts.",
    colorClass: "hover:bg-purple-50",
  },
];
