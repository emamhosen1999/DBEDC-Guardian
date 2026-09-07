import { describe, it, expect, vi } from 'vitest';
import { makeSignalHandler } from '../realtimeSignalHandler';

const snap = (val) => ({ val: () => val });

describe('makeSignalHandler', () => {
  it('calls onSignal for a marker from a different actor', () => {
    const onSignal = vi.fn();
    makeSignalHandler({ selfActorId: 7, onSignal })(snap({ ts: 't1', actor_id: 9, action: 'update' }));
    expect(onSignal).toHaveBeenCalledTimes(1);
    expect(onSignal).toHaveBeenCalledWith({ ts: 't1', actor_id: 9, action: 'update' });
  });

  it('ignores a marker authored by self', () => {
    const onSignal = vi.fn();
    makeSignalHandler({ selfActorId: 7, onSignal })(snap({ ts: 't2', actor_id: 7, action: 'update' }));
    expect(onSignal).not.toHaveBeenCalled();
  });

  it('ignores null / empty / malformed snapshots', () => {
    const onSignal = vi.fn();
    const h = makeSignalHandler({ selfActorId: 7, onSignal });
    h(snap(null));
    h({ val: () => undefined });
    h({});
    h(undefined);
    expect(onSignal).not.toHaveBeenCalled();
  });

  it('treats equivalent numeric legacy actor values as self', () => {
    const onSignal = vi.fn();
    makeSignalHandler({ selfActorId: 7, onSignal })(snap({ ts: 't', actor_id: '7', action: 'update' }));
    expect(onSignal).not.toHaveBeenCalled();
  });

  it('compares string employee codes without lossy numeric coercion', () => {
    const onSignal = vi.fn();
    const handler = makeSignalHandler({ selfActorId: 'EMP-00042', onSignal });

    handler(snap({ ts: 't1', actor_id: 'EMP-00042', action: 'update' }));
    handler(snap({ ts: 't2', actor_id: 'EMP-00043', action: 'update' }));

    expect(onSignal).toHaveBeenCalledTimes(1);
    expect(onSignal).toHaveBeenCalledWith({ ts: 't2', actor_id: 'EMP-00043', action: 'update' });
  });

  it('does not mistake a missing actor for an unknown current user', () => {
    const onSignal = vi.fn();
    makeSignalHandler({ selfActorId: null, onSignal })(snap({ ts: 't', actor_id: null, action: 'update' }));
    expect(onSignal).toHaveBeenCalledTimes(1);
  });
});
