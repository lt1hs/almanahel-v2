"use client";

/**
 * Realtime sockets are unused in the UI today.
 * Keep this stub so accidental imports don't pull pusher-js / laravel-echo
 * into the main bundle (~100KB+).
 */
export function useSocket() {
  return null;
}
