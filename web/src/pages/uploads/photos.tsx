import { api, ApiError } from '@/api/client';
import AppLogoIcon from '@/components/app-logo-icon';
import { Button } from '@/components/ui/button';
import { usePageTitle } from '@/hooks/use-page-title';
import { useMutation, useQuery } from '@tanstack/react-query';
import { FileText, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useParams } from 'react-router-dom';

interface LinkState {
    corporationName: string;
    expiresAt: string;
}

/**
 * The customer's side of an upload link — the one page in this app that
 * asks for no account at all.
 *
 * They are standing on a phone, on mobile data, having tapped a link in a
 * text: so it is one screen, one button, and it says plainly whether the
 * files arrived. Previews come from the files on their own device rather
 * than from the server, which is why nothing here needs credentials.
 */
export default function UploadPhotos() {
    const { t, i18n } = useTranslation('uploads');
    const { token } = useParams();
    const [files, setFiles] = useState<File[]>([]);
    const [previews, setPreviews] = useState<string[]>([]);
    const [error, setError] = useState<string | undefined>();
    const [sent, setSent] = useState(false);
    const fileInput = useRef<HTMLInputElement>(null);

    usePageTitle(t('title'));

    const { data, isLoading, isError, refetch } = useQuery({
        queryKey: ['upload-link', token],
        queryFn: () => api.get<LinkState>(`/api/uploads/${token}`),
        retry: false,
    });

    // Previews are object URLs, which the browser holds until released. A
    // non-image still gets one — it is just not used as an <img> src.
    useEffect(() => {
        const urls = files.map((file) => URL.createObjectURL(file));
        setPreviews(urls);

        return () => urls.forEach((url) => URL.revokeObjectURL(url));
    }, [files]);

    const send = useMutation({
        mutationFn: () => {
            const form = new FormData();
            files.forEach((file) => form.append('photos[]', file));

            return api.post<LinkState>(`/api/uploads/${token}`, form);
        },
        onSuccess: () => {
            setFiles([]);
            setError(undefined);
            setSent(true);
            refetch();
        },
        onError: (err) => {
            if (!(err instanceof ApiError)) throw err;

            // The server names the offending file by index; any one of them
            // being wrong means nothing was sent, so a single line is enough.
            const firstFieldError = Object.values(err.errors)[0]?.[0];
            setError(firstFieldError ?? (err.status === 422 ? t('too_many') : t('failed')));
        },
    });

    if (isLoading) {
        return <Shell>{<p className="text-muted-foreground text-sm">{t('loading')}</p>}</Shell>;
    }

    // Expired, unknown and used-up all look the same from here, because the
    // server deliberately does not distinguish them.
    if (isError || !data) {
        return (
            <Shell>
                <h1 className="text-xl font-semibold">{t('dead_link_title')}</h1>
                <p className="text-muted-foreground mt-2 text-sm">{t('dead_link_body')}</p>
            </Shell>
        );
    }

    return (
        <Shell>
            <h1 className="text-xl font-semibold">{t('heading', { name: data.corporationName })}</h1>

            {sent && files.length === 0 ? (
                <div className="mt-4 rounded-md border p-4">
                    <p className="font-medium">{t('sent_title')}</p>
                    <p className="text-muted-foreground mt-1 text-sm">{t('sent_body')}</p>
                </div>
            ) : (
                <p className="text-muted-foreground mt-2 text-sm">{t('intro')}</p>
            )}

            <input
                ref={fileInput}
                type="file"
                // Lets a phone offer the camera and the photo library
                // directly, alongside a plain file browser for a PDF.
                accept="image/*,application/pdf"
                multiple
                className="hidden"
                onChange={(e) => {
                    const picked = Array.from(e.target.files ?? []);
                    setFiles((current) => [...current, ...picked]);
                    setSent(false);
                    setError(undefined);
                    // So the same file can be chosen again after being
                    // removed.
                    e.target.value = '';
                }}
            />

            {files.length > 0 && (
                // Iterates `files`, not `previews`: those two update on
                // different ticks (previews recompute in an effect, after
                // files changes commit), so indexing into `files` by a
                // `previews`-driven position could momentarily read past the
                // end of a shorter, not-yet-caught-up `files` array — e.g.
                // right after a send clears it. Keying off `files` directly
                // means there is no index that can point at nothing.
                <ul className="mt-4 grid grid-cols-3 gap-2">
                    {files.map((file, index) => (
                        <li key={index} className="relative">
                            {file.type.startsWith('image/') ? (
                                previews[index] && <img src={previews[index]} alt="" className="h-24 w-full rounded-md object-cover" />
                            ) : (
                                <div className="flex h-24 w-full flex-col items-center justify-center gap-1 rounded-md border p-2">
                                    <FileText className="text-muted-foreground h-6 w-6" />
                                    <span className="w-full truncate text-center text-xs">{file.name}</span>
                                </div>
                            )}
                            <button
                                type="button"
                                aria-label={t('remove')}
                                onClick={() => setFiles((current) => current.filter((_, i) => i !== index))}
                                className="bg-background/90 absolute top-1 right-1 rounded-full border p-1"
                            >
                                <X className="h-3 w-3" />
                            </button>
                        </li>
                    ))}
                </ul>
            )}

            <div className="mt-4 flex flex-col gap-2">
                <Button type="button" variant="outline" onClick={() => fileInput.current?.click()}>
                    {files.length === 0 ? t('choose') : t('add_more')}
                </Button>

                {files.length > 0 && (
                    <Button type="button" disabled={send.isPending} onClick={() => send.mutate()}>
                        {send.isPending ? t('sending') : t('send')}
                    </Button>
                )}
            </div>

            {files.length > 0 && <p className="text-muted-foreground mt-3 text-sm">{t('selected', { count: files.length })}</p>}

            {error && <p className="mt-3 text-sm text-red-600 dark:text-red-400">{error}</p>}

            <p className="text-muted-foreground mt-6 text-xs">{t('expires', { date: new Date(data.expiresAt).toLocaleDateString(i18n.language) })}</p>
        </Shell>
    );
}

/**
 * Its own shell rather than the auth layout: that one wraps the logo in a
 * link to the inbox, which is a door a customer cannot open.
 */
function Shell({ children }: { children: React.ReactNode }) {
    return (
        <div className="flex min-h-svh flex-col items-center justify-center p-6">
            <div className="w-full max-w-md">
                <AppLogoIcon className="mb-6 h-8 w-8" />
                {children}
            </div>
        </div>
    );
}
