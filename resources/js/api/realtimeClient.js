import { signInWithCustomToken } from 'firebase/auth';
import { realtimeDb, firebaseAuth } from '@/firebase-config.js';
import { requestJson } from '@/api/client';

let signInPromise = null;

/**
 * Ensure the browser is signed in to Firebase (via a Laravel-minted custom token), once.
 * requestJson('get', '/firebase/token') returns the unwrapped body `{ token }`.
 */
function ensureSignedIn() {
  if (firebaseAuth.currentUser) return Promise.resolve();
  if (!signInPromise) {
    signInPromise = requestJson('get', '/firebase/token')
      .then((res) => signInWithCustomToken(firebaseAuth, res.token))
      .catch((err) => { signInPromise = null; throw err; });
  }
  return signInPromise;
}

/** Resolve the RTDB instance after ensuring Firebase auth. */
export async function getRealtimeDb() {
  await ensureSignedIn();
  return realtimeDb;
}
