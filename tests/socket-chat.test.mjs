import test from 'node:test';
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { once } from 'node:events';
import { io as client } from 'socket.io-client';
import { createChatServer } from '../socket/server.mjs';

test('Socket.IO validates sessions, isolates rooms and revokes logged-out sessions', async () => {
    const secret = 'test-secret-that-is-at-least-32-characters';
    const sessions = { teacher: { user_id: 2, institution_id: 1, session: 'a'.repeat(64) },
        outsider: { user_id: 3, institution_id: 2, session: 'b'.repeat(64) } };
    const app = createServer((request, response) => {
        const identity = sessions[request.headers.cookie];
        response.writeHead(identity ? 200 : 401, { 'Content-Type': 'application/json' });
        response.end(JSON.stringify(identity || {}));
    });
    app.listen(0, '127.0.0.1'); await once(app, 'listening');
    const { http, io } = createChatServer({ appUrl: `http://127.0.0.1:${app.address().port}`, secret });
    http.listen(0, '127.0.0.1'); await once(http, 'listening');
    const url = `http://127.0.0.1:${http.address().port}`;
    const sockets = [];
    const connect = cookie => {
        const socket = client(url, { transports: ['websocket'], reconnection: false,
            extraHeaders: { Cookie: cookie }, auth: { user_id: 2, institution_id: 1 } });
        sockets.push(socket); return socket;
    };
    const publish = (payload, token = secret) => fetch(url + '/internal/events', {
        method: 'POST', headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' }, body: JSON.stringify(payload),
    });
    try {
        const unauthorized = connect('invalid');
        await once(unauthorized, 'connect_error');
        assert.equal(unauthorized.connected, false);
        const teacher = connect('teacher'); await once(teacher, 'connect');
        const outsider = connect('outsider'); await once(outsider, 'connect');
        const payload = { type: 'changed', institution_id: 1, user_id: 2, contact_id: 1, unread_count: 1 };
        assert.equal((await publish(payload, 'bad-secret')).status, 403);
        assert.equal((await publish({ ...payload, user_id: '../other' })).status, 422);
        let leaked = false;
        outsider.on('chat:changed', () => { leaked = true; });
        outsider.emit('join', 'institution:1:user:2');
        const received = once(teacher, 'chat:changed');
        assert.equal((await publish(payload)).status, 204);
        assert.deepEqual((await received)[0], { contact_id: 1, unread_count: 1 });
        await new Promise(resolve => setTimeout(resolve, 80));
        assert.equal(leaked, false, 'another institution cannot receive messages or forge its identity');
        const disconnected = once(teacher, 'disconnect');
        assert.equal((await publish({ type: 'revoke', session: 'a'.repeat(64) })).status, 204);
        await disconnected;
        assert.equal(outsider.connected, true);
    } finally {
        for (const socket of sockets) socket.disconnect();
        await new Promise(resolve => io.close(resolve));
        await new Promise(resolve => app.close(resolve));
    }
});
