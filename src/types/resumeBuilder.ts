// /types/resume.ts
export interface Resume {
  metadata: {
    version: string;
    last_updated: string;
    llm_instructions: string;
  };
  contact: {
    name: string;
    email: string;
    phone: string;
    website: string;
  };
  summary: {
    text: string;
    adjustment_instructions?: string;
  };
  skills: {
    frontend: string[];
    backend: string[];
    cloud_devops: string[];
    data_analytics: string[];
    tools_workflow: string[];
    adjustment_instructions: string;
  };
  professional_experience: Experience[];
  projects: Project[];
  education: Education[];
  notes: {
    final_instructions: string;
  };
}

export interface Experience {
  title: string;
  company: string;
  start_date: string;
  end_date: string;
  description: string;
  achievements: string[];
  relevance_hint?: string;
  adjustment_instructions?: string;
}

export interface Project {
  title: string;
  description: string;
  technologies: string[];
  achievements: string[];
  relevance_hint?: string;
  adjustment_instructions?: string;
}

export interface Education {
  degree: string;
  institution: string;
}
