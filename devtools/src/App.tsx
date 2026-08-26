import { useEffect, useState } from 'react';

const API_URL = import.meta.env.VITE_API_URL;

interface AddressOption {
    id: number;
    corporationName: string;
    addressType: 'phone' | 'email';
    value: string;
}

interface OutboundMessage {
    id: number;
    from: string | null;
    to: string | null;
    body: string;
    messageSid: string | null;
    sentAt: string;
}

export default function App() {
    return (
        <main>
            <h1>Devtools</h1>
            <p className="subtitle">
                Fake a customer texting or emailing in — no real recipient is touched. Only runs against a{' '}
                <code>local</code>-environment API.
            </p>
            <SimulateInbound />
            <OutboundViewer />
        </main>
    );
}

function SimulateInbound() {
    const [addresses, setAddresses] = useState<AddressOption[]>([]);
    const [addressesError, setAddressesError] = useState<string | null>(null);
    const [addressId, setAddressId] = useState<number | null>(null);
    const [from, setFrom] = useState('');
    const [subject, setSubject] = useState('');
    const [body, setBody] = useState('');
    const [status, setStatus] = useState<string | null>(null);
    const [sending, setSending] = useState(false);

    useEffect(() => {
        fetch(`${API_URL}/_dev/addresses`)
            .then((response) => {
                if (!response.ok) throw new Error(`${response.status} ${response.statusText}`);
                return response.json() as Promise<AddressOption[]>;
            })
            .then((data) => {
                setAddresses(data);
                setAddressId((current) => current ?? data[0]?.id ?? null);
            })
            .catch((error: Error) => setAddressesError(error.message));
    }, []);

    const address = addresses.find((item) => item.id === addressId) ?? null;
    const isEmail = address?.addressType === 'email';

    async function send() {
        if (!address || from.trim() === '' || body.trim() === '') return;

        setSending(true);
        setStatus(null);

        try {
            const response = await fetch(`${API_URL}/_dev/${isEmail ? 'inbound-email' : 'inbound-sms'}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(
                    isEmail ? { to: address.value, from, subject: subject || null, text: body } : { to: address.value, from, body },
                ),
            });

            if (!response.ok) {
                const payload = await response.json().catch(() => null);
                throw new Error(payload?.message ?? `${response.status} ${response.statusText}`);
            }

            setStatus('Sent.');
            // Only the body — the sender and subject usually stay the same
            // across a few fake messages from "the same customer".
            setBody('');
        } catch (error) {
            setStatus(error instanceof Error ? error.message : 'Something went wrong.');
        } finally {
            setSending(false);
        }
    }

    return (
        <section>
            <h2>Simulate inbound</h2>

            {addressesError && <p className="error">Couldn't load addresses: {addressesError}</p>}

            <label>
                Address
                <select value={addressId ?? ''} onChange={(e) => setAddressId(Number(e.target.value))}>
                    {addresses.map((item) => (
                        <option key={item.id} value={item.id}>
                            {item.corporationName} — {item.value} ({item.addressType})
                        </option>
                    ))}
                </select>
            </label>

            <label>
                Fake sender ({isEmail ? 'email address' : 'phone number'})
                <input
                    value={from}
                    onChange={(e) => setFrom(e.target.value)}
                    placeholder={isEmail ? 'customer@example.com' : '+45 20 12 34 56'}
                />
            </label>

            {isEmail && (
                <label>
                    Subject
                    <input value={subject} onChange={(e) => setSubject(e.target.value)} placeholder="What's it about?" />
                </label>
            )}

            <label>
                Body
                <textarea value={body} onChange={(e) => setBody(e.target.value)} rows={4} />
            </label>

            <button type="button" disabled={sending || !address || from.trim() === '' || body.trim() === ''} onClick={send}>
                {sending ? 'Sending…' : 'Send'}
            </button>

            {status && <p className={status === 'Sent.' ? 'success' : 'error'}>{status}</p>}
        </section>
    );
}

function OutboundViewer() {
    const [messages, setMessages] = useState<OutboundMessage[]>([]);
    const [error, setError] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);

    function refresh() {
        setLoading(true);
        setError(null);

        fetch(`${API_URL}/_dev/outbound`)
            .then((response) => {
                if (!response.ok) throw new Error(`${response.status} ${response.statusText}`);
                return response.json() as Promise<OutboundMessage[]>;
            })
            .then(setMessages)
            .catch((err: Error) => setError(err.message))
            .finally(() => setLoading(false));
    }

    useEffect(refresh, []);

    return (
        <section>
            <h2>
                Outbound SMS{' '}
                <button type="button" onClick={refresh} disabled={loading}>
                    {loading ? 'Refreshing…' : 'Refresh'}
                </button>
            </h2>
            <p className="subtitle">
                What a reply would have put on the wire. Email needs no equivalent here — dev outbound already lands in{' '}
                <a href="http://localhost:8025" target="_blank" rel="noreferrer">
                    Mailpit
                </a>
                .
            </p>

            {error && <p className="error">Couldn't load outbound messages: {error}</p>}

            {messages.length === 0 ? (
                <p className="subtitle">Nothing sent yet.</p>
            ) : (
                <table>
                    <thead>
                        <tr>
                            <th>From</th>
                            <th>To</th>
                            <th>Body</th>
                            <th>SID</th>
                            <th>Sent</th>
                        </tr>
                    </thead>
                    <tbody>
                        {messages.map((message) => (
                            <tr key={message.id}>
                                <td>{message.from}</td>
                                <td>{message.to}</td>
                                <td>{message.body}</td>
                                <td className="mono">{message.messageSid}</td>
                                <td>{new Date(message.sentAt).toLocaleString()}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            )}
        </section>
    );
}
