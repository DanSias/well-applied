/**
 * Deterministic mock structured data generators.
 * No AI — derived purely from the supplied text inputs.
 * Replace with real parsing output once AI is wired up.
 */

// ── Jobs ────────────────────────────────────────────────────────────────────

export interface ParsedJob {
  summary: string;
  responsibilities: string[];
  requiredQualifications: string[];
  skills: string[];
}

/**
 * Derives a plausible ParsedJob from raw form values.
 * Splits the description into sentences and distributes them across fields.
 */
export function mockParsedJob(
  title: string,
  company: string,
  rawDescription: string,
): ParsedJob {
  // Split on sentence boundaries, filter blanks
  const sentences = rawDescription
    .split(/(?<=[.!?])\s+/)
    .map((s) => s.trim())
    .filter(Boolean);

  const total = sentences.length;

  // Carve rough thirds; fall back to generic text if input is thin
  const responsibilities =
    total > 0 ? sentences.slice(0, Math.ceil(total / 3)) : [`Work on ${title} initiatives at ${company}.`];

  const qualifications =
    total > 1
      ? sentences.slice(Math.ceil(total / 3), Math.ceil((2 * total) / 3))
      : [`Experience relevant to ${title}.`];

  // Pull capitalised words as skill proxies (crude but deterministic)
  const skillWords = Array.from(
    new Set(
      rawDescription.match(/\b[A-Z][a-zA-Z+#]{1,}/g) ?? [],
    ),
  ).slice(0, 6);

  const skills =
    skillWords.length > 0
      ? skillWords
      : ["Communication", "Problem-solving", "Collaboration"];

  return {
    summary: `${title} role at ${company}. ${sentences[0] ?? "Details pending."}`,
    responsibilities,
    requiredQualifications: qualifications,
    skills,
  };
}

// ── Resumes ──────────────────────────────────────────────────────────────────

export interface ParsedResume {
  basics: {
    name: string;
    headline: string;
    summary: string;
  };
  experience: Array<{
    company: string;
    title: string;
    duration: string;
    highlights: string[];
  }>;
  skills: string[];
  education: Array<{
    institution: string;
    degree: string;
    year: string;
  }>;
}

/**
 * Derives a plausible ParsedResume from raw form values.
 * Splits source text into chunks and distributes across resume sections.
 */
export function mockParsedResume(
  resumeTitle: string,
  sourceText: string,
): ParsedResume {
  const sentences = sourceText
    .split(/(?<=[.!?])\s+/)
    .map((s) => s.trim())
    .filter(Boolean);

  const total = sentences.length;

  const highlightSentences =
    total > 0 ? sentences.slice(0, Math.min(3, total)) : ["Contributed to key projects."];

  const skillWords = Array.from(
    new Set(
      sourceText.match(/\b[A-Z][a-zA-Z+#]{1,}/g) ?? [],
    ),
  ).slice(0, 8);

  const skills =
    skillWords.length > 0
      ? skillWords
      : ["TypeScript", "React", "Node.js", "SQL", "Git"];

  const summary =
    sentences[0] ?? `Professional with experience in ${resumeTitle}-related work.`;

  return {
    basics: {
      name: "Dev User",
      headline: resumeTitle,
      summary,
    },
    experience: [
      {
        company: "Previous Company",
        title: resumeTitle,
        duration: "2022 – Present",
        highlights: highlightSentences,
      },
    ],
    skills,
    education: [
      {
        institution: "State University",
        degree: "B.S. Computer Science",
        year: "2021",
      },
    ],
  };
}
