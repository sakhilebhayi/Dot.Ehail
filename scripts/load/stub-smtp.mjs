// SMTP sink for the notification backlog test (DEVELOPMENT ONLY): accepts every message and discards it.
//   node scripts/load/stub-smtp.mjs [port=2525]   GET-free; prints the count on SIGINT.
import net from 'node:net';

const port = Number(process.argv[2] || 2525);
let messages = 0;

net.createServer((socket) => {
    let inData = false;
    let buffer = '';
    socket.write('220 sink.local ESMTP\r\n');
    socket.on('data', (chunk) => {
        buffer += chunk.toString('utf8');
        let idx;
        while (true) {
            if (inData) {
                idx = buffer.indexOf('\r\n.\r\n');
                if (idx === -1) return;
                buffer = buffer.slice(idx + 5);
                inData = false;
                messages++;
                socket.write('250 2.0.0 queued as ' + messages + '\r\n');
                continue;
            }
            idx = buffer.indexOf('\r\n');
            if (idx === -1) return;
            const line = buffer.slice(0, idx);
            buffer = buffer.slice(idx + 2);
            const cmd = line.slice(0, 4).toUpperCase();
            if (cmd === 'EHLO') socket.write('250-sink.local\r\n250 8BITMIME\r\n');
            else if (cmd === 'HELO') socket.write('250 sink.local\r\n');
            else if (cmd === 'DATA') {
                inData = true;
                socket.write('354 go ahead\r\n');
            } else if (cmd === 'QUIT') {
                socket.end('221 bye\r\n');
                return;
            } else socket.write('250 OK\r\n');
        }
    });
    socket.on('error', () => {});
}).listen(port, '127.0.0.1', () => console.log(`smtp sink on 127.0.0.1:${port}`));

process.on('SIGINT', () => {
    console.log(`messages accepted: ${messages}`);
    process.exit(0);
});
