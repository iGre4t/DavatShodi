const net = require('node:net');
const {spawn} = require('node:child_process');
const assert = require('node:assert/strict');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
async function run(scheme, handler) {
  let observed = false;
  const server = net.createServer(socket => handler(socket, () => {observed = true;}));
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  const config = JSON.stringify({bot_token:'12345:FAKE-TOKEN', proxy_url:`${scheme}://127.0.0.1:${server.address().port}`, proxy_username:'test-user', proxy_password:'test-pass'});
  const php = spawn('C:/xampp/php/php.exe', ['-r', `require 'api/lib/system-telegram.php'; $config=json_decode($argv[1],true); echo json_encode(systemTelegramTransport('getMe',[],$config,6));`, config], {cwd:root});
  let output = '', errors = '';
  php.stdout.on('data', data => output += data); php.stderr.on('data', data => errors += data);
  const code = await new Promise(resolve => php.on('exit', resolve));
  await new Promise(resolve => server.close(resolve));
  assert.equal(code, 0, errors); assert.ok(observed, `${scheme} proxy was not used`);
  assert.notEqual(JSON.parse(output).errno, 0, 'Proxy failure must be reported');
  assert.ok(!output.includes('FAKE-TOKEN') && !output.includes('test-pass'), 'Transport leaked credentials');
}
(async () => {
  await run('http', (socket, done) => {
    let request = '';
    socket.on('data', chunk => {
      request += chunk;
      if (!request.includes('\r\n\r\n')) return;
      assert.match(request, /^CONNECT api\.telegram\.org:443 HTTP\/1\.[01]/);
      assert.ok(request.includes('Proxy-Authorization: Basic ' + Buffer.from('test-user:test-pass').toString('base64')));
      done(); socket.end('HTTP/1.1 502 Bad Gateway\r\nContent-Length: 0\r\nConnection: close\r\n\r\n');
    });
  });
  await run('socks5h', (socket, done) => {
    let buffer = Buffer.alloc(0), stage = 0;
    socket.on('data', chunk => {
      buffer = Buffer.concat([buffer, chunk]);
      while (true) {
        if (stage === 0) {
          if (buffer.length < 2 || buffer.length < 2 + buffer[1]) return;
          assert.equal(buffer[0], 5); assert.ok(buffer.subarray(2, 2 + buffer[1]).includes(2));
          buffer = buffer.subarray(2 + buffer[1]); stage = 1; socket.write(Buffer.from([5,2]));
        } else if (stage === 1) {
          if (buffer.length < 2 || buffer.length < 3 + buffer[1]) return;
          const passwordOffset = 2 + buffer[1], total = passwordOffset + 1 + buffer[passwordOffset];
          if (buffer.length < total) return;
          assert.equal(buffer.subarray(2,passwordOffset).toString(), 'test-user');
          assert.equal(buffer.subarray(passwordOffset + 1,total).toString(), 'test-pass');
          buffer = buffer.subarray(total); stage = 2; socket.write(Buffer.from([1,0]));
        } else {
          if (buffer.length < 5 || buffer.length < 7 + buffer[4]) return;
          assert.equal(buffer[3], 3, 'socks5h must resolve Telegram DNS through proxy');
          assert.equal(buffer.subarray(5,5+buffer[4]).toString(), 'api.telegram.org');
          assert.equal(buffer.readUInt16BE(5+buffer[4]), 443);
          done(); socket.end(Buffer.from([5,4,0,1,0,0,0,0,0,0])); return;
        }
      }
    });
  });
  console.log('Real cURL HTTP CONNECT/authentication and SOCKS5 remote DNS/authentication passed.');
})().catch(error => {console.error(error); process.exit(1);});
