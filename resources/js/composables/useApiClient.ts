export async function fetchJson<T>(url: string): Promise<T | null> {
    const response = await fetch(url);
    if (!response.ok) return null;
    return (await response.json()) as T;
}
