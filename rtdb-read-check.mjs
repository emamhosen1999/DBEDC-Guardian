import { initializeApp } from "firebase/app";
import { getAuth, signInWithCustomToken } from "firebase/auth";
import { getDatabase, ref, get } from "firebase/database";
import { readFileSync } from "fs";
const cfg = { apiKey:"AIzaSyCwfSbrgNYCrhdmFIlU7pS7bVVT__lwOgo", authDomain:"dbedc-erp.firebaseapp.com", databaseURL:"https://dbedc-erp-default-rtdb.asia-southeast1.firebasedatabase.app", projectId:"dbedc-erp", appId:"1:551140686722:web:d99b8829aad35e60232d9b" };
const token = readFileSync(process.argv[2], "utf8").trim();
try {
  const app = initializeApp(cfg);
  const auth = getAuth(app);
  const cred = await signInWithCustomToken(auth, token);
  console.log("AUTH_OK uid=" + cred.user.uid);
  const db = getDatabase(app);
  const snap = await get(ref(db, "signals/dbedc-erp/roster/2026-06"));
  console.log("READ_OK " + JSON.stringify(snap.val()));
  process.exit(0);
} catch (e) { console.log("FAIL " + (e && e.code ? e.code : "") + " " + (e && e.message ? e.message : e)); process.exit(1); }