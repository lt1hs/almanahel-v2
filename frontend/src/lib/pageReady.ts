export const PAGE_READY_TIMEOUT_MS = 8000;

export function createPageReadyLatch() {
  let revealed = false;
  let subscriberCount = 0;

  return {
    get revealed() {
      return revealed;
    },
    get subscriberCount() {
      return subscriberCount;
    },
    markReady() {
      revealed = true;
    },
    register() {
      subscriberCount += 1;
      return () => {
        subscriberCount = Math.max(0, subscriberCount - 1);
      };
    },
  };
}
