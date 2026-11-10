// Theme choice: system, light, dark, or night (the driver default: dark from 18:00 to 06:00, otherwise the
// phone's own setting). Returns 'light' | 'dark', or null to follow the system without pinning.
export function resolveTheme(choice, hour = new Date().getHours(), prefersDark = typeof window !== 'undefined' && window.matchMedia('(prefers-color-scheme: dark)').matches) {
    if (choice === 'light' || choice === 'dark') return choice;
    if (choice === 'night') return hour >= 18 || hour < 6 ? 'dark' : prefersDark ? 'dark' : 'light';
    return null;
}
