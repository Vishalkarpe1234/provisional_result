/** @type {import('next').NextConfig} */
const nextConfig = {
  // xlsx's dynamic requires for optional deps trip up webpack's build worker
  // when bundled; keep it as a plain Node require in the server runtime.
  serverExternalPackages: ['xlsx'],
};

export default nextConfig;
