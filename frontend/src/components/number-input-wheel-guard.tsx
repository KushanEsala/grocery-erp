'use client';

import { useEffect } from 'react';

export function NumberInputWheelGuard() {
  useEffect(() => {
    const preventNumberWheel = (event: WheelEvent) => {
      const target = event.target;
      if (!(target instanceof HTMLInputElement) || target.type !== 'number') return;
      if (document.activeElement !== target) return;

      event.preventDefault();
    };

    document.addEventListener('wheel', preventNumberWheel, { passive: false });
    return () => document.removeEventListener('wheel', preventNumberWheel);
  }, []);

  return null;
}
