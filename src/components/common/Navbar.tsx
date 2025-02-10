"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";

const navItems = [
  { title: "Dashboard", href: "/dashboard" },
  { title: "Jobs", href: "/jobs" },
  { title: "Resume", href: "/resume" },
  { title: "Cover Letter", href: "/cover-letter" },
  // { title: "Prompts", href: "/prompts" },
];

export default function Navbar() {
  const pathname = usePathname();

  return (
    <nav className="bg-white shadow-md">
      <div className="max-w-6xl mx-auto px-4 py-3 flex justify-between">
        <h1 className="text-xl font-bold">Well Applied</h1>
        <ul className="flex space-x-6">
          {navItems.map((item) => (
            <li key={item.href}>
              <Link href={item.href}>
                <span
                  className={`cursor-pointer text-gray-700 hover:text-blue-600 transition ${
                    pathname === item.href ? "font-bold text-blue-600" : ""
                  }`}>
                  {item.title}
                </span>
              </Link>
            </li>
          ))}
        </ul>
      </div>
    </nav>
  );
}
