import { ReactNode } from 'react';

export interface DetailsGridItem {
    label: string;
    value: ReactNode;
    fullWidth?: boolean;
}

export default function DetailsGrid({ items }: { items: DetailsGridItem[] }) {
    return (
        <dl className="grid grid-cols-2 gap-x-8 gap-y-3 rounded-xl border p-4 text-sm sm:grid-cols-3">
            {items.map((item) => (
                <div key={item.label} className={item.fullWidth ? 'col-span-full' : undefined}>
                    <dt className="text-xs text-neutral-500 dark:text-neutral-400">{item.label}</dt>
                    <dd className="mt-0.5 font-medium">{item.value}</dd>
                </div>
            ))}
        </dl>
    );
}
