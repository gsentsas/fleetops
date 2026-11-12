declare module 'react' {
    export function useEffect(callback: () => void | (() => void), dependencies?: unknown[]): void;
    export function useMemo<T>(callback: () => T, dependencies?: unknown[]): T;
    export function useCallback<T extends (...a: any[]) => any>(callback: T, dependencies?: unknown[]): T;
    export function useState<T>(initial: T | (() => T)): [T, (next: T) => void];
}
declare module '@fleetbase/sdk' {
    export default class Fleetbase {
        constructor(key: unknown, options: {host: unknown});
        getAdapter(): unknown;
    }
}
declare module 'react-native' {
    export const NativeModules: Record<string, unknown>;
}
