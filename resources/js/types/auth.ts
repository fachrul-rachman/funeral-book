export type User = {
    id: string;
    name: string;
    role: 'admin' | 'pic' | 'manager' | 'superadmin';
};

export type Auth = {
    user: User | null;
};
