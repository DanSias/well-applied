"use client";

import { Education } from "@/types/resumeBuilder";

interface EducationEditorProps {
  education: Education[];
  setEducation: (education: Education[]) => void;
}

export default function EducationEditor({
  education,
  setEducation,
}: EducationEditorProps) {
  function handleEducationChange<K extends keyof Education>(
    index: number,
    field: K,
    value: Education[K]
  ) {
    const updated = [...education];
    updated[index] = { ...updated[index], [field]: value };
    setEducation(updated);
  }

  function addEducation() {
    const newEdu: Education = {
      degree: "",
      institution: "",
    };
    setEducation([...education, newEdu]);
  }

  function deleteEducation(index: number) {
    const updated = [...education];
    updated.splice(index, 1);
    setEducation(updated);
  }

  return (
    <div className="space-y-6">
      <h2 className="text-2xl font-bold">Education</h2>

      {education.map((edu, eduIndex) => (
        <div
          key={eduIndex}
          className="border rounded-lg p-4 space-y-4 shadow-sm">
          <div>
            <label className="block font-semibold">Degree</label>
            <input
              type="text"
              className="w-full p-2 border rounded"
              value={edu.degree}
              onChange={(e) =>
                handleEducationChange(eduIndex, "degree", e.target.value)
              }
            />
          </div>

          <div>
            <label className="block font-semibold">Institution</label>
            <input
              type="text"
              className="w-full p-2 border rounded"
              value={edu.institution}
              onChange={(e) =>
                handleEducationChange(eduIndex, "institution", e.target.value)
              }
            />
          </div>

          <div className="text-right">
            <button
              type="button"
              onClick={() => deleteEducation(eduIndex)}
              className="text-red-600 text-sm">
              🗑️ Delete Education
            </button>
          </div>
        </div>
      ))}

      <div className="text-center">
        <button
          type="button"
          onClick={addEducation}
          className="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded mt-4">
          + Add New Education
        </button>
      </div>
    </div>
  );
}
