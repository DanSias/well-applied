import { prisma } from "@/lib/prisma";

const DEV_USER = {
  email: "dev@well-applied.local",
  name: "Dev User",
};

/**
 * Returns a stable placeholder user for development.
 * Upserts on first call so the user always exists in the DB.
 * Replace with real auth session lookup once Auth.js is wired up.
 */
export async function getDevUser() {
  return prisma.user.upsert({
    where: { email: DEV_USER.email },
    update: {},
    create: DEV_USER,
  });
}
