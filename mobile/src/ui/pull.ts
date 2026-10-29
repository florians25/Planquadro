/**
 * Pull-to-Refresh für einen Scroll-Container. Zusätzlich bietet jeder Bildschirm
 * einen sichtbaren „Aktualisieren“-Button (Barrierefreiheit, Desktop).
 */
const THRESHOLD = 70;

export function attachPullToRefresh(scroller: HTMLElement, indicator: HTMLElement, getHandler: () => (() => Promise<void>) | null): void {
  let startY = 0;
  let pulling = false;
  let busy = false;
  let dist = 0;

  const reset = () => {
    indicator.style.transform = '';
    indicator.classList.remove('visible', 'armed');
    dist = 0;
  };

  scroller.addEventListener(
    'touchstart',
    (e) => {
      if (busy || scroller.scrollTop > 0 || !getHandler() || e.touches.length !== 1) return;
      startY = e.touches[0]!.clientY;
      pulling = true;
    },
    { passive: true },
  );

  scroller.addEventListener(
    'touchmove',
    (e) => {
      if (!pulling) return;
      dist = Math.max(0, e.touches[0]!.clientY - startY);
      if (dist <= 0) return;
      const d = Math.min(dist * 0.5, 90);
      indicator.classList.add('visible');
      indicator.classList.toggle('armed', dist > THRESHOLD);
      indicator.style.transform = `translateY(${d}px)`;
    },
    { passive: true },
  );

  scroller.addEventListener('touchend', async () => {
    if (!pulling) return;
    pulling = false;
    const handler = getHandler();
    if (dist > THRESHOLD && handler) {
      busy = true;
      indicator.classList.add('busy');
      try {
        await handler();
      } finally {
        busy = false;
        indicator.classList.remove('busy');
        reset();
      }
    } else {
      reset();
    }
  });
}
