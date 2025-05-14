import { Resume } from "@/types/resumeBuilder";

export const exampleResume: Resume = {
  metadata: {
    version: "1.0",
    last_updated: "2025-05-13",
    llm_instructions:
      "Please tailor the resume for the provided job description. Emphasize the most relevant experiences and reorder sections if needed to prioritize alignment with the job. Keep the structure the same. DO NOT remove sections unless instructed. Adjust wording to match the tone and keywords from the job description.",
  },
  contact: {
    name: "Daniel Sias",
    email: "daniel.sias@gmail.com",
    phone: "(407) 272-1720",
    website: "danielsias.dev",
  },
  summary: {
    text: "Full-Stack Developer specializing in scalable, data-driven applications with expertise in React, Node.js, TypeScript, and cloud platforms. Proven ability to streamline workflows, automate processes, and integrate APIs, reducing manual effort and improving efficiency by 85%. Experienced in building real-time analytics platforms, AI-powered tools, and business intelligence solutions that drive informed decision-making. Passionate about performance optimization, automation, and bridging engineering with business objectives to deliver impactful solutions.",
    adjustment_instructions:
      "Update to align with the job description focus areas such as AI, leadership, frontend/backend balance, or domain expertise.",
  },
  skills: {
    frontend: [
      "React.js",
      "Next.js",
      "TypeScript",
      "API Integration",
      "TailwindCSS",
      "User-Centered Design",
    ],
    backend: [
      "Node.js",
      "Express.js",
      "FastAPI",
      "Django",
      "PostgreSQL",
      "BigQuery",
      "RESTful APIs",
      "OAuth",
      "PHP",
    ],
    cloud_devops: [
      "Google Cloud",
      "AWS",
      "Microsoft Azure",
      "Supabase",
      "CI/CD Pipelines",
      "Docker",
    ],
    data_analytics: [
      "Python",
      "Marketing Automation",
      "Data Engineering",
      "Business Intelligence",
      "AI-Powered Tools",
    ],
    tools_workflow: [
      "Git",
      "Agile/Scrum",
      "API Design",
      "Performance Optimization",
      "Automation",
    ],
    adjustment_instructions:
      "If appropriate, prioritize or re-order the most job-relevant skills higher in the list. Do not remove any skills unless specified.",
  },
  professional_experience: [
    {
      title: "Data & Analytics Lead Developer",
      company: "Pearson Online Learning Services",
      start_date: "2015-09",
      end_date: "2024-07",
      description:
        "Developed data-driven applications, automation tools, and real-time analytics dashboards to optimize marketing performance, finance forecasting, and sales operations. Focused on scalable full-stack development, API integrations, and workflow automation to enhance efficiency.",
      achievements: [
        "Built a React/Node analytics platform integrating BigQuery & Salesforce, cutting report generation time by 85% and automating KPI tracking for $25M+ in marketing spend.",
        "Developed a marketing spend forecasting system, replacing manual spreadsheets with a centralized web-based platform, saving teams 20+ hours per month and improving budget accuracy.",
        "Designed and deployed API-driven automation to connect Salesforce, BigQuery, and ad platforms, enabling real-time marketing performance tracking.",
        "Led the creation of an email marketing performance tracker, integrating Google Analytics API & Salesforce Marketing Cloud to improve engagement and A/B testing efficiency.",
        "Built a PPC tracking link generator, standardizing URL parameters to ensure accurate attribution, minimize tracking errors, and improve campaign performance across multiple advertising platforms.",
        "Collaborated on the Salesforce migration and data warehouse setup, transitioning legacy systems to BigQuery and cloud-based reporting while ensuring data integrity.",
      ],
      relevance_hint: "High",
      adjustment_instructions:
        "Tailor language toward technical leadership, AI tools, data platform modernization if relevant to the job.",
    },
    {
      title: "Search Engine Optimization Analyst",
      company: "Pearson Online Learning Services",
      start_date: "2013-06",
      end_date: "2015-09",
      description:
        "Drove organic growth strategies, data-driven SEO analysis, and performance tracking to optimize digital marketing efforts for 34 university programs. Focused on technical SEO, keyword strategy, content optimization, and automation tools to enhance search rankings and lead generation.",
      achievements: [
        "Managed a $1.3MM SEO budget, leveraging advanced data analysis to improve content strategy and drive conversions across multiple programs.",
        "Developed real-time SEO performance dashboards using Tableau and Google Analytics API, streamlining reporting and improving decision-making.",
        "Automated keyword monitoring and site audits, integrating Ahrefs API to track rankings, backlinks, and site health.",
        "Optimized conversion funnels through A/B testing, on-page SEO enhancements, and technical audits, improving lead volume and engagement.",
        "Standardized reporting workflows, replacing complex spreadsheets with interactive data visualizations for actionable insights.",
      ],
      relevance_hint: "Medium",
      adjustment_instructions:
        "Frame SEO and analytics experience as part of broader data-driven decision making.",
    },
    {
      title: "Founder & Full Stack Developer / Marketing Consultant",
      company: "Liquid Gravity Engineering",
      start_date: "2005-07",
      end_date: "2013-06",
      description:
        "Founded and led a marketing technology firm, developing custom automation, CRM integrations, and conversion-optimized sales funnels for high-profile clients across diverse industries. Built scalable web applications, ecommerce solutions, and performance-tracking systems to enhance marketing efficiency and drive revenue growth.",
      achievements: [
        "Engineered a one-click upsell system, dynamically generating personalized post-checkout offers to increase revenue, resulting in a 37% boost in conversion rates.",
        "Developed CRM integrations with Salesforce, Infusionsoft, and 1ShoppingCart, automating lead tracking, follow-ups, and campaign performance analysis.",
        "Built and automated A/B testing frameworks, leveraging Google Analytics and Optimizely to refine conversion funnels and optimize marketing campaigns.",
        "Designed and deployed custom marketing automation workflows, improving email sequences, lead scoring, and retargeting strategies to enhance engagement and conversion rates.",
        "Implemented real-time performance dashboards, centralizing KPI tracking and marketing attribution for clients across multiple industries.",
        "Consulted on strategic marketing initiatives, optimizing website speed, mobile experiences, and user engagement strategies to improve lead generation by 201.2% without increasing ad spend.",
      ],
      relevance_hint: "Medium",
      adjustment_instructions:
        "Emphasize entrepreneurship, technical breadth, and marketing-tech integrations.",
    },
  ],
  projects: [
    {
      title: "Nexus: Data-Driven Analytics & Reporting System",
      description:
        "Led development of enterprise analytics platform that automated KPI tracking across 5 departments, reducing report generation time by 70% and enabling $2M in marketing spend optimizations through ML-powered forecasting.",
      technologies: [
        "React.js",
        "Node.js",
        "BigQuery",
        "Prisma",
        "PostgreSQL",
        "Salesforce API",
      ],
      achievements: [
        "Automated data processing, reducing manual reporting by 85% and enabling tracking of $25M+ in marketing spend.",
        "Integrated Salesforce data into BigQuery to consolidate marketing lead and spend data into one interactive source.",
        "Built custom API endpoints for dynamic data retrieval, ensuring scalability and real-time decision-making.",
        "Replaced spreadsheet-based workflows, saving teams 20+ hours per week spent in reporting and forecasting.",
      ],
      relevance_hint: "High",
      adjustment_instructions:
        "Emphasize KPI tracking, automation, and scale if relevant to the job.",
    },
    {
      title: "SEO Analytics & Competitive Insights Dashboard",
      description:
        "Developed a custom dashboard to track keyword rankings, competitor insights, and backlink health, providing real-time SEO performance metrics to enhance search visibility and drive organic growth strategies.",
      technologies: [
        "Node.js",
        "React.js",
        "Ahrefs API",
        "PostgreSQL",
        "Express.js",
      ],
      achievements: [
        "Integrated Ahrefs API to automate SEO tracking, eliminating the need for manual rank monitoring.",
        "Developed a real-time reporting dashboard, visualizing keyword trends, backlink health, and competitor data.",
        "Enabled marketing teams to refine SEO strategies, leading to data-driven content optimization and better rankings.",
        "Replaced manual spreadsheets with automated insights, saving hours per week in SEO reporting.",
      ],
      relevance_hint: "Medium",
      adjustment_instructions:
        "Frame SEO work as part of broader data analytics and performance tracking.",
    },
  ],
  education: [
    {
      degree: "Master of Science in Optics",
      institution: "University of Central Florida",
    },
    {
      degree: "Master of Science in Management",
      institution: "University of Florida",
    },
    {
      degree: "BS in Engineering Physics",
      institution: "Embry-Riddle Aeronautical University",
    },
  ],
  notes: {
    final_instructions:
      "Once the object is updated, ensure that field names remain exactly the same. Do not add new fields. Do not remove required fields. Only adjust content.",
  },
};
