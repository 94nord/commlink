import { initializeTheme } from '@/hooks/use-appearance';
import i18n from '@/i18n';
import '@/index.css';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { I18nextProvider } from 'react-i18next';
import { BrowserRouter } from 'react-router-dom';
import { App } from './App';

initializeTheme();

const queryClient = new QueryClient();

createRoot(document.getElementById('root')!).render(
    <StrictMode>
        <BrowserRouter>
            <I18nextProvider i18n={i18n}>
                <QueryClientProvider client={queryClient}>
                    <App />
                </QueryClientProvider>
            </I18nextProvider>
        </BrowserRouter>
    </StrictMode>,
);
