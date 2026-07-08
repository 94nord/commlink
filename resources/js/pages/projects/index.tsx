import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Projects', href: '/projects' },
];

interface ProjectListItem {
    id: number;
    name: string;
    workOrderCount: number;
}

export default function ProjectsIndex({ projects }: { projects: ProjectListItem[] }) {
    const [open, setOpen] = useState(false);

    const { data, setData, post, errors, processing, reset } = useForm({ name: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('projects.store'), {
            onSuccess: () => reset(),
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Projects" />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <div className="flex items-center justify-between">
                    <h1 className="text-xl font-semibold">Projects</h1>

                    <Dialog open={open} onOpenChange={(next) => { setOpen(next); if (!next) reset(); }}>
                        <DialogTrigger asChild>
                            <Button>New project</Button>
                        </DialogTrigger>
                        <DialogContent>
                            <DialogHeader>
                                <DialogTitle>New project</DialogTitle>
                            </DialogHeader>

                            <form onSubmit={submit} className="space-y-4">
                                <div className="grid gap-2">
                                    <Label htmlFor="name">Project name</Label>
                                    <Input
                                        id="name"
                                        value={data.name}
                                        onChange={(e) => setData('name', e.target.value)}
                                        autoFocus
                                        placeholder="e.g. Nyhavnsgade 12"
                                    />
                                    <InputError message={errors.name} />
                                </div>

                                <div className="flex justify-end gap-3 pt-2">
                                    <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                                        Cancel
                                    </Button>
                                    <Button disabled={processing}>Create project</Button>
                                </div>
                            </form>
                        </DialogContent>
                    </Dialog>
                </div>

                {projects.length === 0 ? (
                    <p className="text-sm text-neutral-600 dark:text-neutral-400">
                        No projects yet. Create one to start registering apartments.
                    </p>
                ) : (
                    <ul className="divide-y rounded-xl border">
                        {projects.map((project) => (
                            <li key={project.id}>
                                <Link
                                    href={route('projects.show', project.id)}
                                    prefetch
                                    className="flex items-center justify-between p-4 hover:bg-neutral-50 dark:hover:bg-neutral-900"
                                >
                                    <span className="font-medium">{project.name}</span>
                                    <span className="text-sm text-neutral-600 dark:text-neutral-400">
                                        {project.workOrderCount} {project.workOrderCount === 1 ? 'apartment' : 'apartments'}
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </AppLayout>
    );
}
