import { api, ApiError } from '@/api/client';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Check, Copy, ImagePlus, RefreshCw } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

interface UploadLink {
    url: string;
    expiresAt: string;
}

/**
 * The link a customer uploads photos through, shown so it can be copied and
 * sent however the firm likes.
 *
 * There is only ever one live link per conversation: generating another
 * closes the one before it, so what this dialog shows is always the URL that
 * actually works. That is also why it reads the current link rather than
 * minting on open — reopening to copy again must not quietly replace the
 * link the customer is already using.
 */
export default function UploadLinkDialog({ conversationId }: { conversationId: number }) {
    const { t, i18n } = useTranslation('inbox');
    const queryClient = useQueryClient();
    const [open, setOpen] = useState(false);
    const [copied, setCopied] = useState(false);
    const [error, setError] = useState<string | undefined>();

    const queryKey = ['upload-link', conversationId];

    const { data, isLoading } = useQuery({
        queryKey,
        queryFn: () => api.get<{ link: UploadLink | null }>(`/api/conversations/${conversationId}/upload-link`),
        enabled: open,
    });

    const generate = useMutation({
        mutationFn: () => api.post<{ link: UploadLink }>(`/api/conversations/${conversationId}/upload-link`),
        onSuccess: (result) => {
            queryClient.setQueryData(queryKey, result);
            setCopied(false);
            setError(undefined);
        },
        onError: (err) => {
            if (err instanceof ApiError) setError(err.message);
            else throw err;
        },
    });

    const link = data?.link ?? null;

    async function copy() {
        if (!link) return;

        await navigator.clipboard.writeText(link.url);
        setCopied(true);
        window.setTimeout(() => setCopied(false), 2000);
    }

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                setOpen(next);
                setCopied(false);
                setError(undefined);
            }}
        >
            <DialogTrigger asChild>
                <Button type="button" variant="outline">
                    <ImagePlus className="h-4 w-4" />
                    {t('upload_link')}
                </Button>
            </DialogTrigger>

            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{t('upload_link_title')}</DialogTitle>
                    <DialogDescription>{t('upload_link_description')}</DialogDescription>
                </DialogHeader>

                {isLoading ? (
                    <p className="text-muted-foreground text-sm">{t('loading')}</p>
                ) : link ? (
                    <div className="space-y-3">
                        <div className="flex gap-2">
                            <Input readOnly value={link.url} onFocus={(e) => e.currentTarget.select()} />
                            <Button type="button" variant="outline" onClick={copy} aria-label={t('copy_link')}>
                                {copied ? <Check className="h-4 w-4" /> : <Copy className="h-4 w-4" />}
                            </Button>
                        </div>

                        <p className="text-muted-foreground text-sm">
                            {t('upload_link_expires', {
                                date: new Date(link.expiresAt).toLocaleString(i18n.language),
                            })}
                        </p>
                    </div>
                ) : (
                    <p className="text-muted-foreground text-sm">{t('upload_link_none')}</p>
                )}

                {error && <p className="text-sm text-red-600 dark:text-red-400">{error}</p>}

                <div className="flex justify-end">
                    <Button
                        type="button"
                        // `link` reads null until the fetch resolves, same as
                        // a real "no link yet" — without also checking
                        // isLoading here, the button would commit to the
                        // solid "Generate" look and then flip to outline the
                        // instant an existing link turns up.
                        variant={isLoading || link ? 'outline' : 'default'}
                        // Before the fetch resolves, `link` is still null, so
                        // this would otherwise read "Generate" even when a
                        // link already exists — clicking then would replace
                        // it out from under whoever's already using it.
                        disabled={isLoading || generate.isPending}
                        onClick={() => generate.mutate()}
                    >
                        <RefreshCw className="h-4 w-4" />
                        {link ? t('upload_link_renew') : t('upload_link_generate')}
                    </Button>
                </div>

                {/* Said plainly, because it is the one surprising thing this
                    dialog does. */}
                {link && <p className="text-muted-foreground text-xs">{t('upload_link_renew_warning')}</p>}
            </DialogContent>
        </Dialog>
    );
}
