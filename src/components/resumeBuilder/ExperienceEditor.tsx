"use client";

import { Experience } from "@/types/resumeBuilder";
// import { v4 as uuidv4 } from "uuid";
// import { useState } from "react";

interface ExperienceEditorProps {
  experiences: Experience[];
  setExperiences: (experiences: Experience[]) => void;
}

export default function ExperienceEditor({
  experiences,
  setExperiences,
}: ExperienceEditorProps) {
  // Helper: Update a field within an experience
  function handleExperienceChange<K extends keyof Experience>(
    index: number,
    field: K,
    value: Experience[K]
  ) {
    const updated = [...experiences];
    updated[index] = { ...updated[index], [field]: value };
    setExperiences(updated);
  }

  // Helper: Update an achievement inside an experience
  function handleAchievementChange(
    experienceIndex: number,
    achievementIndex: number,
    value: string
  ) {
    const updated = [...experiences];
    updated[experienceIndex].achievements[achievementIndex] = value;
    setExperiences(updated);
  }

  // Add a new achievement
  function addAchievement(experienceIndex: number) {
    const updated = [...experiences];
    updated[experienceIndex].achievements.push("");
    setExperiences(updated);
  }

  // Delete an achievement
  function deleteAchievement(
    experienceIndex: number,
    achievementIndex: number
  ) {
    const updated = [...experiences];
    updated[experienceIndex].achievements.splice(achievementIndex, 1);
    setExperiences(updated);
  }

  // Add a new experience
  function addExperience() {
    const newExperience: Experience = {
      title: "",
      company: "",
      start_date: "",
      end_date: "",
      description: "",
      relevance_hint: "",
      adjustment_instructions: "",
      achievements: [],
    };
    setExperiences([...experiences, newExperience]);
  }

  // Delete an experience
  function deleteExperience(index: number) {
    const updated = [...experiences];
    updated.splice(index, 1);
    setExperiences(updated);
  }

  return (
    <div className="space-y-6">
      <h2 className="text-2xl font-bold">Professional Experience</h2>

      {experiences.map((exp, expIndex) => (
        <div
          key={expIndex}
          className="border rounded-lg p-4 space-y-4 shadow-sm">
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label className="block font-semibold">Company</label>
              <input
                type="text"
                className="w-full p-2 border rounded"
                value={exp.company}
                onChange={(e) =>
                  handleExperienceChange(expIndex, "company", e.target.value)
                }
              />
            </div>
            <div>
              <label className="block font-semibold">Role / Title</label>
              <input
                type="text"
                className="w-full p-2 border rounded"
                value={exp.title}
                onChange={(e) =>
                  handleExperienceChange(expIndex, "title", e.target.value)
                }
              />
            </div>
            <div>
              <label className="block font-semibold">Start Date</label>
              <input
                type="text"
                className="w-full p-2 border rounded"
                placeholder="YYYY-MM"
                value={exp.start_date}
                onChange={(e) =>
                  handleExperienceChange(expIndex, "start_date", e.target.value)
                }
              />
            </div>
            <div>
              <label className="block font-semibold">End Date</label>
              <input
                type="text"
                className="w-full p-2 border rounded"
                placeholder="YYYY-MM or Present"
                value={exp.end_date}
                onChange={(e) =>
                  handleExperienceChange(expIndex, "end_date", e.target.value)
                }
              />
            </div>
          </div>

          <div>
            <label className="block font-semibold">Description</label>
            <textarea
              className="w-full p-2 border rounded"
              rows={3}
              value={exp.description}
              onChange={(e) =>
                handleExperienceChange(expIndex, "description", e.target.value)
              }
            />
          </div>

          <div>
            <h3 className="font-semibold">Achievements</h3>
            <div className="flex flex-col gap-2 mt-2">
              {exp.achievements.map((ach, achIndex) => (
                <div key={achIndex} className="flex items-center gap-2">
                  <input
                    type="text"
                    className="flex-1 p-2 border rounded"
                    value={ach}
                    onChange={(e) =>
                      handleAchievementChange(
                        expIndex,
                        achIndex,
                        e.target.value
                      )
                    }
                  />
                  <button
                    type="button"
                    onClick={() => deleteAchievement(expIndex, achIndex)}
                    className="text-red-500 text-sm">
                    ✕
                  </button>
                </div>
              ))}
              <button
                type="button"
                onClick={() => addAchievement(expIndex)}
                className="text-blue-500 text-sm mt-2">
                + Add Achievement
              </button>
            </div>
          </div>

          <div className="text-right">
            <button
              type="button"
              onClick={() => deleteExperience(expIndex)}
              className="text-red-600 text-sm">
              🗑️ Delete Experience
            </button>
          </div>
        </div>
      ))}

      <div className="text-center">
        <button
          type="button"
          onClick={addExperience}
          className="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded mt-4">
          + Add New Experience
        </button>
      </div>
    </div>
  );
}
