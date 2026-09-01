import { renderPicker } from '@/lib/pages/picker';

export async function GET() {
  const html = await renderPicker();
  return new Response(html, { headers: { 'content-type': 'text/html; charset=utf-8' } });
}
