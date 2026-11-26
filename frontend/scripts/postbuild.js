import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const distDir = path.resolve(__dirname, '../dist');
const indexHtml = path.join(distDir, 'index.html');

if (fs.existsSync(indexHtml)) {
  const apacheRules = `Options -MultiViews\nRewriteEngine On\nRewriteCond %{REQUEST_FILENAME} -f [OR]\nRewriteCond %{REQUEST_FILENAME} -d\nRewriteRule ^ - [L]\nRewriteRule ^table/([a-fA-F0-9]{64})/?$ table/index.html?token=$1 [QSA,L]\nRewriteRule ^ index.html [L]\n`;
  fs.writeFileSync(path.join(distDir, '.htaccess'), apacheRules);
  const routes = [
    'about',
    'menu',
    'gallery',
    'contact',
    'payment/result',
    'track-order',
    'account',
    'account/login',
    'account/register',
    'account/organisations',
    'account/documents',
    'admin',
    'admin/login',
    'admin/categories',
    'admin/items',
    'admin/tables',
    'admin/qr-codes',
    'admin/waiter',
    'admin/kitchen',
    'admin/reverb',
    'admin/delivery',
    'admin/delivery-zones',
    'admin/menu-overview',
    'admin/staff',
    'admin/roles',
    'admin/orders',
    'admin/settings',
    'admin/content',
    'admin/audit-logs',
    'admin/profile',
    'admin/financials',
    'admin/payment-allocations',
    'admin/commercial-documents',
    'admin/chat',
    'delivery',
    'delivery/checkout',
    'delivery/confirmation',
    'table',
  ];
  for (const route of routes) {
    const routeDir = path.join(distDir, route);
    if (!fs.existsSync(routeDir)) {
      fs.mkdirSync(routeDir, { recursive: true });
    }
    fs.copyFileSync(indexHtml, path.join(routeDir, 'index.html'));
  }
  // Also create 404.html pointing to index.html for servers that support it
  fs.copyFileSync(indexHtml, path.join(distDir, '404.html'));
  console.log('Postbuild: Route fallback directories and 404.html generated successfully.');
}
