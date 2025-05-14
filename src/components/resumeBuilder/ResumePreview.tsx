"use client";

import { useState } from "react";
import { Resume } from "@/types/resumeBuilder";
import { Inter, Roboto, Lato } from "next/font/google";

const inter = Inter({ subsets: ["latin"] });
const roboto = Roboto({ subsets: ["latin"], weight: ["400", "700"] });
const lato = Lato({ subsets: ["latin"], weight: ["400", "700"] });

interface ResumePreviewProps {
  resume: Resume;
}

export const fontOptions = {
  inter: inter.className,
  roboto: roboto.className,
  lato: lato.className,
  georgia: "font-serif",
  garamond: "font-serif", // Same base, unless you load it separately later
};

export const fontSettings = {
  inter: "leading-relaxed",
  roboto: "leading-normal",
  lato: "leading-relaxed",
  georgia: "leading-snug",
  garamond: "leading-snug",
};

export default function ResumePreview({ resume }: ResumePreviewProps) {
  const [fontChoice, setFontChoice] =
    useState<keyof typeof fontOptions>("inter");

  return (
    <>
      <div>
        <select
          value={fontChoice}
          onChange={(e) =>
            setFontChoice(e.target.value as keyof typeof fontOptions)
          }
          className="border p-2 rounded mb-4">
          <option value="inter">Inter</option>
          <option value="roboto">Roboto</option>
          <option value="lato">Lato</option>
          <option value="georgia">Georgia (Serif)</option>
          <option value="garamond">Garamond (Serif)</option>
        </select>
      </div>
      <div
        className={`${fontOptions[fontChoice]} ${fontSettings[fontChoice]} w-[8.5in] min-h-[11in] max-h-[11in] mx-auto p-12 bg-white text-black font-serif text-[11pt] leading-relaxed`}>
        {/* Contact */}
        <div className="text-center mb-6">
          <h1 className="text-3xl font-bold">{resume.contact.name}</h1>
          <p className="text-sm mt-2">
            {resume.contact.email} | {resume.contact.phone} |{" "}
            {resume.contact.website}
          </p>
        </div>

        {/* Summary */}
        <section className="mb-6">
          {/* <h2 className="text-xl font-bold border-b pb-1 mb-2">Summary</h2> */}
          <p>{resume.summary.text}</p>
        </section>

        {/* Skills */}
        <section className="mb-6">
          <h2 className="text-xl font-bold border-b pb-1 mb-2">Skills</h2>
          <div className=" text-sm">
            <div>
              <strong>Frontend:</strong> {resume.skills.frontend.join(", ")}
            </div>
            <div>
              <strong>Backend:</strong> {resume.skills.backend.join(", ")}
            </div>
            <div>
              <strong>Cloud & DevOps:</strong>{" "}
              {resume.skills.cloud_devops.join(", ")}
            </div>
            <div>
              <strong>Data & Analytics:</strong>{" "}
              {resume.skills.data_analytics.join(", ")}
            </div>
            <div>
              <strong>Tools & Workflow:</strong>{" "}
              {resume.skills.tools_workflow.join(", ")}
            </div>
          </div>
        </section>

        {/* Professional Experience */}
        {resume.professional_experience.length > 0 && (
          <section className="mb-6">
            <h2 className="text-xl font-bold border-b pb-1 mb-2">
              Professional Experience
            </h2>
            {resume.professional_experience.map((exp, idx) => (
              <div key={idx} className="mb-4">
                <div className="flex justify-between">
                  <h3 className="font-semibold">
                    {exp.title} - {exp.company}
                  </h3>
                  <p className="text-sm">
                    {exp.start_date} – {exp.end_date}
                  </p>
                </div>
                <p className="text-sm mt-1">{exp.description}</p>
                <ul className="list-disc list-inside mt-2 text-sm space-y-1">
                  {exp.achievements.map((ach, aidx) => (
                    <li key={aidx}>{ach}</li>
                  ))}
                </ul>
              </div>
            ))}
          </section>
        )}

        {/* Projects */}
        {resume.projects.length > 0 && (
          <section className="mb-6">
            <h2 className="text-xl font-bold border-b pb-1 mb-2">Projects</h2>
            {resume.projects.map((proj, idx) => (
              <div key={idx} className="mb-4">
                <h3 className="font-semibold">{proj.title}</h3>
                <p className="text-sm mt-1">{proj.description}</p>
                <p className="text-sm mt-1">
                  <strong>Technologies:</strong> {proj.technologies.join(", ")}
                </p>
                <ul className="list-disc list-inside mt-2 text-sm space-y-1">
                  {proj.achievements.map((ach, aidx) => (
                    <li key={aidx}>{ach}</li>
                  ))}
                </ul>
              </div>
            ))}
          </section>
        )}

        {/* Education */}
        {resume.education.length > 0 && (
          <section>
            <h2 className="text-xl font-bold border-b pb-1 mb-2">Education</h2>
            <ul className="text-sm space-y-1">
              {resume.education.map((edu, idx) => (
                <li key={idx}>
                  <strong>{edu.degree}</strong> — {edu.institution}
                </li>
              ))}
            </ul>
          </section>
        )}
      </div>
    </>
  );
}
