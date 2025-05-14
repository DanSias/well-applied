"use client";

import { Project } from "@/types/resumeBuilder";

interface ProjectEditorProps {
  projects: Project[];
  setProjects: (projects: Project[]) => void;
}

export default function ProjectEditor({
  projects,
  setProjects,
}: ProjectEditorProps) {
  function handleProjectChange<K extends keyof Project>(
    index: number,
    field: K,
    value: Project[K]
  ) {
    const updated = [...projects];
    updated[index] = { ...updated[index], [field]: value };
    setProjects(updated);
  }

  function handleAchievementChange(
    projectIndex: number,
    achievementIndex: number,
    value: string
  ) {
    const updated = [...projects];
    updated[projectIndex].achievements[achievementIndex] = value;
    setProjects(updated);
  }

  function addAchievement(projectIndex: number) {
    const updated = [...projects];
    updated[projectIndex].achievements.push("");
    setProjects(updated);
  }

  function deleteAchievement(projectIndex: number, achievementIndex: number) {
    const updated = [...projects];
    updated[projectIndex].achievements.splice(achievementIndex, 1);
    setProjects(updated);
  }

  function addProject() {
    const newProject: Project = {
      title: "",
      description: "",
      technologies: [],
      achievements: [],
    };
    setProjects([...projects, newProject]);
  }

  function deleteProject(index: number) {
    const updated = [...projects];
    updated.splice(index, 1);
    setProjects(updated);
  }

  return (
    <div className="space-y-6">
      <h2 className="text-2xl font-bold">Projects</h2>

      {projects.map((proj, projIndex) => (
        <div
          key={projIndex}
          className="border rounded-lg p-4 space-y-4 shadow-sm">
          <div>
            <label className="block font-semibold">Title</label>
            <input
              type="text"
              className="w-full p-2 border rounded"
              value={proj.title}
              onChange={(e) =>
                handleProjectChange(projIndex, "title", e.target.value)
              }
            />
          </div>

          <div>
            <label className="block font-semibold">Description</label>
            <textarea
              className="w-full p-2 border rounded"
              rows={3}
              value={proj.description}
              onChange={(e) =>
                handleProjectChange(projIndex, "description", e.target.value)
              }
            />
          </div>

          <div>
            <label className="block font-semibold">Technologies</label>
            <input
              type="text"
              className="w-full p-2 border rounded"
              value={proj.technologies.join(", ")}
              onChange={(e) =>
                handleProjectChange(
                  projIndex,
                  "technologies",
                  e.target.value.split(",").map((t) => t.trim())
                )
              }
              placeholder="Comma-separated list (React, Node.js, etc.)"
            />
          </div>

          <div>
            <h3 className="font-semibold">Achievements</h3>
            <div className="flex flex-col gap-2 mt-2">
              {proj.achievements.map((ach, achIndex) => (
                <div key={achIndex} className="flex items-center gap-2">
                  <input
                    type="text"
                    className="flex-1 p-2 border rounded"
                    value={ach}
                    onChange={(e) =>
                      handleAchievementChange(
                        projIndex,
                        achIndex,
                        e.target.value
                      )
                    }
                  />
                  <button
                    type="button"
                    onClick={() => deleteAchievement(projIndex, achIndex)}
                    className="text-red-500 text-sm">
                    ✕
                  </button>
                </div>
              ))}
              <button
                type="button"
                onClick={() => addAchievement(projIndex)}
                className="text-blue-500 text-sm mt-2">
                + Add Achievement
              </button>
            </div>
          </div>

          <div className="text-right">
            <button
              type="button"
              onClick={() => deleteProject(projIndex)}
              className="text-red-600 text-sm">
              🗑️ Delete Project
            </button>
          </div>
        </div>
      ))}

      <div className="text-center">
        <button
          type="button"
          onClick={addProject}
          className="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded mt-4">
          + Add New Project
        </button>
      </div>
    </div>
  );
}
