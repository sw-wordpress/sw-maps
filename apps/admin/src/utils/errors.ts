import { isApiError } from '@/api/client';

/** Message for an unknown failure; ApiError messages come translated from the server. */
export function errorMessage(error: unknown, fallback: string): string {
  if (isApiError(error)) {
    const detail = error.details[0];
    return detail ? `${error.message} (${detail.field}: ${detail.message})` : error.message;
  }
  return fallback;
}
