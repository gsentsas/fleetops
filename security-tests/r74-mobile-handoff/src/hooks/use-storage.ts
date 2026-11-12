export const storage: {removeItem(key: string): void} = {removeItem(){}};
export default function useStorage(key: string): [any, (value: any)=>void] {
    return [undefined,()=>{}];
}
