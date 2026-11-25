import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

// Required by pusher-js to be on window
(window as unknown as { Pusher: typeof Pusher }).Pusher = Pusher;

function getToken(): string {
  try {
    return localStorage.getItem('special_delicacies_token') ?? '';
  } catch {
    return '';
  }
}

const broadcaster = (import.meta.env.VITE_BROADCAST_DRIVER as string) || 'reverb';
const authEndpoint = `${(import.meta.env.VITE_API_BASE_URL as string).replace(/\/$/, '').replace(/\/api\/v1$/, '')}/api/v1/broadcasting/auth`;
const channelAuthorization = {
  endpoint: authEndpoint,
  transport: 'ajax' as const,
  headersProvider: () => ({
    Accept: 'application/json',
    Authorization: `Bearer ${getToken()}`,
  }),
};
const echo = broadcaster === 'pusher'
  ? new Echo({
      broadcaster: 'pusher' as const,
      key: (import.meta.env.VITE_PUSHER_APP_KEY as string) || (import.meta.env.VITE_REVERB_APP_KEY as string),
      cluster: import.meta.env.VITE_PUSHER_APP_CLUSTER as string,
      forceTLS: true,
      channelAuthorization,
    })
  : new Echo({
      broadcaster: 'reverb' as const,
      key: import.meta.env.VITE_REVERB_APP_KEY as string,
      wsHost: (import.meta.env.VITE_REVERB_HOST as string) || new URL(import.meta.env.VITE_API_BASE_URL as string).hostname,
      wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 8080),
      wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 443),
      forceTLS: (import.meta.env.VITE_REVERB_SCHEME as string) === 'https',
      enabledTransports: ['ws', 'wss'] as Array<'ws' | 'wss'>,
      channelAuthorization,
    });

export default echo;
