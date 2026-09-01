import { randomBytes, timingSafeEqual } from 'crypto';
import { cookies } from 'next/headers';

const COOKIE = 'csrf';

/** Returns the current CSRF token, issuing one via cookie if none exists yet. */
export async function issueToken(): Promise<string> {
  const store = await cookies();
  const existing = store.get(COOKIE)?.value;
  if (existing) return existing;

  const token = randomBytes(16).toString('hex');
  store.set(COOKIE, token, { httpOnly: true, sameSite: 'lax', secure: true, path: '/' });
  return token;
}

export async function hasValidToken(posted: string | null): Promise<boolean> {
  if (!posted) return false;
  const store = await cookies();
  const expected = store.get(COOKIE)?.value;
  if (!expected) return false;

  const a = Buffer.from(expected);
  const b = Buffer.from(posted);
  return a.length === b.length && timingSafeEqual(a, b);
}
