import { apiUrl } from '@/api/client';
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';
import { type MessageAttachment } from '@/pages/inbox/shared';
import { Download, Expand, FileText } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

function formatBytes(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

/**
 * One attachment in a bubble.
 *
 * An image gets a thumbnail that opens a full-size lightbox on click, plus a
 * small hover overlay so both actions (expand, download) are discoverable
 * without guessing. Anything else — a PDF today — gets a plain file card:
 * nothing in this app previews a PDF inline, so there is no expand action to
 * offer, only a download. The server backs that up independently: a
 * non-image content type is always served with a forced download regardless
 * of what the frontend does, so this split is a UX choice here, not the
 * actual security boundary.
 */
export default function AttachmentView({ attachment, onMediaLoad }: { attachment: MessageAttachment; onMediaLoad: () => void }) {
    const { t } = useTranslation('inbox');
    const [open, setOpen] = useState(false);
    const [loaded, setLoaded] = useState(false);
    const isImage = attachment.contentType.startsWith('image/');
    const downloadUrl = `${apiUrl(attachment.url)}?download=1`;

    if (!isImage) {
        return (
            // Same footprint as an image thumbnail below, so a file next to a
            // photo — or several files together — reads as one consistent
            // grid instead of a wide bar next to a small square.
            <a
                href={downloadUrl}
                className="flex h-40 w-40 flex-col items-center justify-center gap-1.5 rounded-md border border-black/10 bg-white/60 p-3 text-center hover:bg-white dark:border-white/10 dark:bg-black/20 dark:hover:bg-black/40"
            >
                <FileText className="h-8 w-8 shrink-0 text-neutral-500" />
                <span className="line-clamp-2 w-full text-xs font-medium break-words">{attachment.filename ?? t('attachment_fallback_name')}</span>
                <span className="flex items-center gap-1 text-xs text-neutral-500">
                    {formatBytes(attachment.byteSize)}
                    <Download className="h-3.5 w-3.5" />
                </span>
                <span className="sr-only">{t('download_attachment')}</span>
            </a>
        );
    }

    function settle() {
        setLoaded(true);
        // A picture that will not load still settles to its final height,
        // and the view has to follow.
        onMediaLoad();
    }

    return (
        <>
            <div className="group relative h-40 w-40">
                {/* Shown until the image actually has pixels — otherwise the
                    box is either blank or a broken-image icon for however
                    long the fetch takes. */}
                {!loaded && <Skeleton className="absolute inset-0 rounded-md" />}
                <button type="button" onClick={() => setOpen(true)} className="block h-full w-full">
                    <img
                        src={apiUrl(attachment.url)}
                        alt={t('attachment_alt')}
                        crossOrigin="use-credentials"
                        onLoad={settle}
                        onError={settle}
                        // A fixed box, cropped to fill it: a portrait next to
                        // a landscape next to a square would otherwise make a
                        // multi-image message look like a ransom note. The
                        // full, uncropped image is still one click away in
                        // the lightbox below.
                        className={cn('h-40 w-40 rounded-md object-cover', !loaded && 'invisible')}
                    />
                </button>

                <div className="absolute top-1.5 right-1.5 flex gap-1 opacity-0 transition-opacity group-focus-within:opacity-100 group-hover:opacity-100">
                    <button type="button" onClick={() => setOpen(true)} className="rounded-full bg-black/60 p-1.5 text-white hover:bg-black/80">
                        <Expand className="h-3.5 w-3.5" />
                        <span className="sr-only">{t('view_full_size')}</span>
                    </button>
                    <a href={downloadUrl} className="rounded-full bg-black/60 p-1.5 text-white hover:bg-black/80">
                        <Download className="h-3.5 w-3.5" />
                        <span className="sr-only">{t('download_attachment')}</span>
                    </a>
                </div>
            </div>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-w-[90vw] border-none bg-transparent p-2 shadow-none sm:rounded-none">
                    <DialogTitle className="sr-only">{t('view_full_size')}</DialogTitle>
                    <img
                        src={apiUrl(attachment.url)}
                        alt={t('attachment_alt')}
                        crossOrigin="use-credentials"
                        className="max-h-[85vh] w-auto rounded-md object-contain"
                    />
                </DialogContent>
            </Dialog>
        </>
    );
}
