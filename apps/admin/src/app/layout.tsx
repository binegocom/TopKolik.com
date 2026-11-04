import type { Metadata } from "next";
import Link from "next/link";
import "./globals.css";

export const metadata: Metadata = {
  title: "Topkolik.com | Operasyon ve Moderasyon Konsolu",
  description: "Yetkili moderatör ve operasyon personeli için canlı maç arenası ve güvenlik yönetimi.",
};

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <html lang="tr" className="dark">
      <body className="min-h-screen bg-graphite text-[#F4F7F5] flex flex-col antialiased">
        {/* Operations Header */}
        <header className="sticky top-0 z-50 bg-graphite-surface/90 backdrop-blur-md border-b border-graphite-border">
          <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
            <div className="flex items-center space-x-4">
              <Link href="/" className="flex items-center space-x-2">
                <span className="w-2.5 h-2.5 rounded-full bg-red-500 animate-pulse shadow-[0_0_10px_#EF4444]" />
                <span className="text-xl font-black tracking-tight text-white">TOPKOLİK</span>
                <span className="text-xs font-bold tracking-widest px-2 py-0.5 rounded bg-red-600/20 text-red-400 border border-red-500/30 uppercase">
                  OPERASYON
                </span>
              </Link>
            </div>

            <div className="flex items-center space-x-3">
              <span className="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-bold bg-pitch/40 text-electric border border-pitch">
                MFA Korumalı
              </span>
              <span className="text-xs text-[#9EBAAF]">Yetkili Moderatör (Staff #101)</span>
            </div>
          </div>
        </header>

        {/* Content */}
        <main className="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-6">{children}</main>

        {/* Footer */}
        <footer className="border-t border-graphite-border bg-graphite-surface py-4">
          <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center text-xs text-[#5E796F]">
            Topkolik.com İç Operasyon Portalı • Tüm işlemler append-only denetim kütüğüne (Audit Ledger) kaydedilir.
          </div>
        </footer>
      </body>
    </html>
  );
}
