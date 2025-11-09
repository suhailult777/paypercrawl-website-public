import { PrismaClient } from '@prisma/client'

// Support both sync import usage (existing code) and async, connection-aware access.
// - `db` provides a PrismaClient singleton for backwards compatibility
// - `getDb()` ensures a single connection attempt and returns a connected client

const globalForPrisma = globalThis as unknown as {
  prisma?: PrismaClient
  prismaConnectPromise?: Promise<PrismaClient>
}

export const db =
  globalForPrisma.prisma ??
  new PrismaClient({
    log: ['query'],
  })

// Best-effort connect in background (non-blocking) for legacy callers
// Avoid awaiting at module scope to keep environments without top-level await happy.
void db.$connect().catch(() => {
  // Swallow here; `getDb()` will surface connection errors to callers.
});

if (process.env.NODE_ENV !== 'production') globalForPrisma.prisma = db

export async function getDb(): Promise<PrismaClient> {
  // Return existing connected client when available
  if (globalForPrisma.prisma) return globalForPrisma.prisma;

  // Ensure only one connect attempt occurs in parallel
  if (!globalForPrisma.prismaConnectPromise) {
    const client = db; // reuse the same singleton instance
    globalForPrisma.prismaConnectPromise = client.$connect().then(() => {
      globalForPrisma.prisma = client;
      return client;
    });
  }
  return globalForPrisma.prismaConnectPromise;
}