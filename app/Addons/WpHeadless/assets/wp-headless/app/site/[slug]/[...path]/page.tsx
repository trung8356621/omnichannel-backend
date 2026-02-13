export default async function SitePathPage({
  params,
}: {
  params: Promise<{ slug: string; path: string[] }>;
}) {
  const { slug, path } = await params;
  const pathStr = path?.length ? path.join('/') : '';
  return (
    <main style={{ padding: '2rem', fontFamily: 'system-ui' }}>
      <h1>Site: {slug}</h1>
      <p>Path: {pathStr || '(root)'}</p>
    </main>
  );
}
