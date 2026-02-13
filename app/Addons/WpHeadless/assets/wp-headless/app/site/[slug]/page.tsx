export default async function SiteBySlugPage({
  params,
}: {
  params: Promise<{ slug: string }>;
}) {
  const { slug } = await params;
  return (
    <main style={{ padding: '2rem', fontFamily: 'system-ui' }}>
      <h1>Site: {slug}</h1>
      <p>Nội dung headless cho site slug &quot;{slug}&quot;</p>
    </main>
  );
}
