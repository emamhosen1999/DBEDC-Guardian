/**
 * Pure handler for an RTDB signal snapshot — testable without React or Firebase.
 * Fires onSignal only for a real marker authored by a DIFFERENT actor than self
 * (so a user's own change doesn't trigger a redundant refetch after their optimistic update).
 */
export function makeSignalHandler({ selfActorId, onSignal }) {
  return (snapshot) => {
    const marker = snapshot?.val?.() ?? null;
    if (!marker) return;
    if (
      selfActorId !== null
      && selfActorId !== undefined
      && marker.actor_id !== null
      && marker.actor_id !== undefined
      && String(marker.actor_id) === String(selfActorId)
    ) return;
    onSignal(marker);
  };
}
