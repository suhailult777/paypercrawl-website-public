// server.ts - Next.js Standalone + Socket.IO
import { setupSocket } from '@/lib/socket';
import { createServer } from 'http';
import { Server } from 'socket.io';
import next from 'next';

const dev = process.env.NODE_ENV !== 'production';
const currentPort = 3000;
const hostname = '0.0.0.0';

// Custom server with Socket.IO integration
async function createCustomServer() {
  try {
    // Create Next.js app
    const nextApp = next({ 
      dev,
      dir: process.cwd(),
      // In production, use the current directory where .next is located
      conf: dev ? undefined : { distDir: './.next' }
    });

    await nextApp.prepare();
    const handle = nextApp.getRequestHandler();

    // Create HTTP server that will handle both Next.js and Socket.IO
  const server = createServer((req, res) => {
      // Skip socket.io requests from Next.js handler
      if (req.url?.startsWith('/api/socketio')) {
        return;
      }

      // Manual admin authentication check for /admin routes (custom server bypasses middleware)
      try {
        const pathname = new URL(req.url || '/', `http://${req.headers.host || 'localhost'}`).pathname;
        
        // Read cookies once
        const cookieHeader = req.headers.cookie || '';
        const cookies = Object.fromEntries(
          cookieHeader.split(';').filter(Boolean).map(part => {
            const [k, ...rest] = part.split('=');
            return [k.trim(), decodeURIComponent(rest.join('=').trim())];
          })
        );
        const adminSession = cookies['admin_session'] === '1';

        // If already authenticated, prevent showing login page; redirect to /admin
        if ((pathname === '/admin/login' || pathname === '/admin/login/') && adminSession) {
          res.writeHead(302, { Location: '/admin' });
          res.end();
          return;
        }

        // Allow admin login page and session endpoint without pre-auth
        if (pathname === '/admin/login' || pathname === '/admin/login/') {
          return handle(req, res);
        }

        // Block all other /admin routes without authentication
        if (pathname.startsWith('/admin')) {
          // Block all other /admin routes without authentication
          if (!adminSession) {
            // Redirect to login
            res.writeHead(302, { Location: '/admin/login' });
            res.end();
            return;
          }
        }
      } catch (error) {
        // If URL parsing fails or any error occurs, just pass through to Next.js
        console.error('Server auth check error:', error);
      }

      handle(req, res);
    });

    // Setup Socket.IO
    const io = new Server(server, {
      path: '/api/socketio',
      cors: {
        origin: "*",
        methods: ["GET", "POST"]
      }
    });

    setupSocket(io);

    // Start the server
    server.listen(currentPort, hostname, () => {
      console.log(`> Ready on http://${hostname}:${currentPort}`);
      console.log(`> Socket.IO server running at ws://${hostname}:${currentPort}/api/socketio`);
    });

  } catch (err) {
    console.error('Server startup error:', err);
    process.exit(1);
  }
}

// Start the server
createCustomServer();
