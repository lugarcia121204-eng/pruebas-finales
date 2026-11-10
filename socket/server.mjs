import { createServer } from 'node:http';
import { timingSafeEqual } from 'node:crypto';
import { pathToFileURL } from 'node:url';
import { Server } from 'socket.io';

const positiveId = value => Number.isSafeInteger(value) && value > 0;
const room = (institution, user) => `institution:${institution}:user:${user}`;

export function createChatServer({ appUrl, secret, origins = [new URL(appUrl).origin], revalidateMs = 60000 }) {
    if (!secret || secret.length < 32) throw new Error('SOCKET_IO_SECRET must contain at least 32 characters');
    const authenticate = async cookie => {
        if (!cookie) throw new Error('Unauthorized');
        const response = await fetch(new URL('/institution-chat/socket-session', appUrl), {
            headers: { Cookie: cookie, Accept: 'application/json' },
            redirect: 'manual', signal: AbortSignal.timeout(5000),
        });
        if (!response.ok) throw new Error('Unauthorized');
        const identity = await response.json();
        if (!positiveId(identity.user_id) || !positiveId(identity.institution_id)
            || !/^[a-f0-9]{64}$/.test(identity.session || '')) throw new Error('Unauthorized');
        return identity;
    };
    const http = createServer(async (request, response) => {
        if (request.url === '/health' && request.method === 'GET') {
            response.writeHead(200).end('OK'); return;
        }
        if (request.url !== '/internal/events' || request.method !== 'POST') {
            response.writeHead(404).end(); return;
        }
        const supplied = Buffer.from(request.headers.authorization || '');
        const expected = Buffer.from(`Bearer ${secret}`);
        if (supplied.length !== expected.length || !timingSafeEqual(supplied, expected)) {
            response.writeHead(403).end(); return;
        }
        try {
            let body = '';
            for await (const chunk of request) {
                body += chunk;
                if (Buffer.byteLength(body) > 4096) { response.writeHead(413).end(); return; }
            }
            const event = JSON.parse(body);
            if (event.type === 'revoke' && /^[a-f0-9]{64}$/.test(event.session || '')) {
                io.in(`session:${event.session}`).disconnectSockets(true);
            } else if (event.type === 'changed' && positiveId(event.institution_id)
                && positiveId(event.user_id) && positiveId(event.contact_id)
                && Number.isSafeInteger(event.unread_count) && event.unread_count >= 0) {
                io.to(room(event.institution_id, event.user_id)).emit('chat:changed', {
                    contact_id: event.contact_id, unread_count: event.unread_count,
                });
            } else { response.writeHead(422).end(); return; }
            response.writeHead(204).end();
        } catch { if (!response.headersSent) response.writeHead(400).end(); }
    });
    const io = new Server(http, {
        transports: ['websocket'], serveClient: false, maxHttpBufferSize: 4096,
        cors: { origin: origins, credentials: true },
        allowRequest: (request, callback) => callback(null, !request.headers.origin || origins.includes(request.headers.origin)),
    });
    io.use(async (socket, next) => {
        try {
            socket.data.identity = await authenticate(socket.handshake.headers.cookie);
            next();
        } catch { next(new Error('No se pudo validar la sesión del chat.')); }
    });
    io.on('connection', socket => {
        const identity = socket.data.identity;
        // Never accept room names or identities supplied by the browser.
        socket.join(room(identity.institution_id, identity.user_id));
        socket.join(`session:${identity.session}`);
        const interval = setInterval(async () => {
            try {
                const current = await authenticate(socket.handshake.headers.cookie);
                if (current.user_id !== identity.user_id || current.institution_id !== identity.institution_id
                    || current.session !== identity.session) socket.disconnect(true);
            } catch { socket.disconnect(true); }
        }, revalidateMs);
        interval.unref();
        socket.on('disconnect', () => clearInterval(interval));
    });
    return { http, io };
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    try { process.loadEnvFile('.env'); } catch (error) { if (error.code !== 'ENOENT') throw error; }
    const { http } = createChatServer({
        appUrl: process.env.SOCKET_IO_APP_URL || process.env.APP_URL,
        secret: process.env.SOCKET_IO_SECRET,
        origins: (process.env.SOCKET_IO_ORIGINS || process.env.APP_URL).split(',').map(value => new URL(value.trim()).origin),
    });
    const host = process.env.SOCKET_IO_HOST || '127.0.0.1';
    const port = Number(process.env.SOCKET_IO_PORT || 8095);
    http.listen(port, host, () => console.log(`Socket.IO chat listening on ${host}:${port}`));
    http.on('error', error => { console.error(error.message); process.exitCode = 1; });
}
