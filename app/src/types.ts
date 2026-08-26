/** Mirrors web/src/types/index.ts — the API is the same for both apps. */
export interface User {
    id: number;
    name: string;
    email: string;
    is_admin: boolean;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
}
