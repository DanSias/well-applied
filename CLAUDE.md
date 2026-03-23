# CLAUDE.md

## Project Overview
Well Applied is a SaaS app for managing job applications and generating tailored resumes using AI.

Core features:
- Job tracking
- Resume parsing
- Tailored resume generation
- PDF export

Tech stack:
- Next.js (App Router)
- TypeScript
- Tailwind CSS
- Prisma + Postgres
- Auth.js
- Zod

---

## Instructions for Claude

### General Behavior
- Do not over-engineer solutions
- Prefer simple, maintainable code
- Stay within the requested scope
- Do not introduce new libraries unless necessary

---

### File Creation Rules
- Do NOT create:
  - TASK.md
  - PLAN.md
  - TODO.md
  - or any markdown files outside `/docs`
- Only create files that are necessary for the application
- Prefer editing existing files over creating new ones

---

### Architecture Rules
- Follow the existing folder structure
- Keep business logic out of UI components
- Use server actions where appropriate
- Keep components modular and reusable

---

### Data & Backend Rules
- Use Prisma for all database access
- Use Zod for validation
- Never store unvalidated AI output
- Keep structured JSON as the source of truth (not raw text)

---

### AI Integration Rules
- All AI outputs must conform to defined schemas
- Do not return raw or loosely structured responses
- Normalize all AI data before storing

---

### UI Rules
- Use Tailwind CSS
- Follow existing design system and patterns
- Do not introduce new UI libraries
- Keep UI clean, minimal, and consistent

---

### Scope Control
Only build what is explicitly requested.

Do NOT:
- add extra features
- expand beyond current task
- refactor unrelated code

---

## Development Approach

Build in vertical slices:
1. Job → Resume → Tailored Resume
2. Validate flow end-to-end
3. Then expand

---

## Notes
- PDFs are generated from structured data
- Layout is system-controlled (Smart Layout Engine direction)
- This is a production-minded SaaS project, not a prototype