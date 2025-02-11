# Well Applied

[![Live Demo](https://img.shields.io/badge/Live_Demo-Visit-blue)](https://wellapplied.danielsias.dev)

**Well Applied** is an AI-powered tool designed to assist job seekers in optimizing their job search process. By generating structured AI prompts, users can tailor resumes, conduct company research, and prepare for interviews efficiently. This project demonstrates advanced knowledge of **Next.js**, **React**, **TypeScript**, and **Tailwind CSS**, while showcasing skills in modular UI design and state management with **localStorage**.

---

## 🔧 Features

- **Tailored Resume & Cover Letter Prompts**  
  Generate AI-driven prompts to align resumes and cover letters with specific job descriptions.

- **Company Research & Interview Prep**  
  Create prompts for researching companies and preparing for interviews with targeted questions.

- **Job Posting Management**  
  Save, view, and manage job descriptions with the ability to add jobs directly from the prompts page.

- **Refinement Phrases for AI Responses**  
  Use pre-built phrases to fine-tune AI outputs, ensuring better alignment with job application goals.

- **Clarifying Questions Integration**  
  Include AI-driven clarifying questions to enhance the relevance of generated prompts.

---

## 🧰 Tech Stack

- **Frontend:** Next.js (React with TypeScript), Tailwind CSS
- **UI Components:** Headless UI (for accessible modals and dropdowns)
- **Data Persistence:** localStorage (no backend for the first iteration)
- **Notifications:** react-hot-toast

---

## 🔄 Project Structure

```
/src
  /app
    /prompts
      page.tsx           # Main prompts generation page
    /jobs
      page.tsx           # Job posting management page
  /components
    /prompts
      JobSelector.tsx
      PromptTypeSelector.tsx
      ClarifyingQuestionsSelector.tsx
      GeneratedPromptDisplay.tsx
      RefinementPhrases.tsx
    /jobs
      JobForm.tsx        # Form component for adding new jobs
    /common
      Modal.tsx          # Reusable modal component using Headless UI
  /utils
    jobStorage.ts        # LocalStorage utilities for jobs
    resumeStorage.ts     # LocalStorage utilities for resumes
  /types
    jobPosting.ts        # Type definitions for job postings
```

---

## 🚀 Getting Started

### Prerequisites

- **Node.js** (v14 or higher)
- **npm** (v6 or higher)

### Installation

1. **Clone the repository:**

   ```bash
   git clone https://github.com/your-username/well-applied.git
   cd well-applied
   ```

2. **Install dependencies:**

   ```bash
   npm install
   ```

3. **Run the development server:**
   ```bash
   npm run dev
   ```
   Open [http://localhost:3000](http://localhost:3000) in your browser.

---

## 🔄 Key Components Overview

### **1. JobSelector**

Navigate and select saved job postings with a clean dropdown interface.

### **2. PromptTypeSelector**

Switch between generating prompts for resumes, cover letters, or interview preparation.

### **3. ClarifyingQuestionsSelector**

Let users specify the number of clarifying questions the AI should ask before generating the prompt.

### **4. GeneratedPromptDisplay**

View and copy generated prompts with ease.

### **5. RefinementPhrases**

Provides users with common phrases to refine AI responses for better results.

---

## 🌟 Project Highlights

- **Modular Component Design:** Clean and reusable components with a focus on scalability.
- **Accessible UI:** Headless UI integration ensures accessibility best practices.
- **State Management:** Efficient use of **localStorage** for managing user data without backend dependencies.
- **Customizable AI Interactions:** Includes features like clarifying questions and prompt refinements to optimize AI-generated outputs.

---

## 🎓 Learning Objectives

This project was built to:

- Showcase expertise in **full-stack JavaScript** development with **Next.js**.
- Demonstrate **clean, maintainable code** using **TypeScript**.
- Create **modular and reusable components** that support future feature expansions.
- Implement **localStorage** as a simple yet effective solution for data persistence.

---

## 🚜 Future Enhancements

- **API Integration:** Add backend support for user authentication and cloud-based data storage.
- **Advanced AI Integration:** Use AI APIs (e.g., OpenAI) for real-time prompt generation and feedback.
- **User Dashboard:** Implement a comprehensive dashboard for managing job applications and progress tracking.
- **Custom Prompt Templates:** Allow users to save and reuse their own prompt templates.

---

## 💌 Contact

**Daniel Sias**  
[danielsias.dev](https://danielsias.dev)  
[LinkedIn](https://www.linkedin.com/in/daniel-sias)  
Email: [daniel.sias@gmail.com](mailto:daniel.sias@gmail.com)

---

## 📊 License

This project is licensed under the **MIT License**.
