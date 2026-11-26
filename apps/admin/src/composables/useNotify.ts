import { Notify } from 'quasar';

export function useNotify() {
  return {
    success: (message: string): void => {
      Notify.create({ type: 'positive', message, timeout: 3000 });
    },
    error: (message: string): void => {
      Notify.create({ type: 'negative', message, timeout: 6000 });
    },
  };
}
