"use client";

import { Resume } from "@/types/resumeBuilder";
import ExperienceEditor from "./ExperienceEditor";
import ProjectEditor from "./ProjectEditor";
import EducationEditor from "./EducationEditor";

interface ResumeEditorFormProps {
  resume: Resume;
  setResume: (resume: Resume) => void;
}

export default function ResumeEditorForm({
  resume,
  setResume,
}: ResumeEditorFormProps) {
  return (
    <div className="p-4 space-y-6">
      <div>
        <label className="font-semibold">Summary</label>
        <textarea
          className="w-full p-2 border rounded"
          value={resume.summary.text}
          onChange={(e) =>
            setResume({ ...resume, summary: { text: e.target.value } })
          }
          rows={4}
        />
      </div>

      {/* Experience Section */}
      <ExperienceEditor
        experiences={resume.professional_experience}
        setExperiences={(experiences) =>
          setResume({ ...resume, professional_experience: experiences })
        }
      />

      {/* Projects Section */}
      <ProjectEditor
        projects={resume.projects}
        setProjects={(projects) => setResume({ ...resume, projects: projects })}
      />

      {/* Education Section */}
      <EducationEditor
        education={resume.education}
        setEducation={(education) =>
          setResume({ ...resume, education: education })
        }
      />
    </div>
  );
}
