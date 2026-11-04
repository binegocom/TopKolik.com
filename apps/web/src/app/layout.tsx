import type { Metadata } from "next";
import Link from "next/link";
import "./globals.css";

export const metadata: Metadata = {
  title: "Topkolik.com | Taraftarlar İçin Sosyal Maç Arenası ve Tahmin Ligi",
  description:
    "Maç anında, maç öncesi ve sonrası tribün tartışmaları, canlı anketler, taktik reaksiyonlar ve ücretsiz tahmin ligi.",
};

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <html lang="tr" className="dark">
      <body className="min-h-screen bg-graphite text-[#F4F7F5] flex flex-col antialiased">
        {/* Header */}
        <header className="sticky top-0 z-50 bg-graphite-surface/90 backdrop-blur-md border-b border-graphite-border">
          <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
            <div className="flex items-center space-x-6">
              <Link href="/" className="flex items-center space-x-2">
                <span className="w-2.5 h-2.5 rounded-full bg-electric animate-pulse shadow-[0_0_10px_#7CFF4D]" />
                <span className="text-xl font-black tracking-tight text-electric">TOPKOLİK</span>
                <span className="text-xl font-medium tracking-tight text-white">.COM</span>
              </Link>
              <nav className="hidden md:flex items-center space-x-1">
                <Link
                  href="/"
                  className="px-3 py-1.5 rounded-lg text-sm font-semibold text-white hover:bg-graphite-card transition-colors"
                >
                  Maç Arenası
                </Link>
                <Link
                  href="/predictions"
                  className="px-3 py-1.5 rounded-lg text-sm font-semibold text-[#9EBAAF] hover:text-white hover:bg-graphite-card transition-colors"
                >
                  Tahminler
                </Link>
                <Link
                  href="/rankings"
                  className="px-3 py-1.5 rounded-lg text-sm font-semibold text-[#9EBAAF] hover:text-white hover:bg-graphite-card transition-colors"
                >
                  Taraftar Ligi
                </Link>
              </nav>
            </div>

            {/* Live Match Bar Snapshot */}
            <div className="flex items-center space-x-4">
              <div className="hidden sm:flex items-center space-x-3 bg-pitch-dark/60 border border-pitch px-3 py-1.5 rounded-full">
                <span className="w-2 h-2 rounded-full bg-red-500 animate-ping" />
                <span className="text-xs font-bold text-white">GS 2 - 1 FB</span>
                <span className="text-[11px] font-semibold text-electric">74&apos;</span>
              </div>
              <div className="px-2.5 py-1 rounded-md text-xs font-bold bg-graphite-card border border-graphite-border text-electric">
                TR
              </div>
            </div>
          </div>
        </header>

        {/* Content */}
        <main className="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-6">{children}</main>

        {/* Footer */}
        <footer className="border-t border-graphite-border bg-graphite-surface py-6">
          <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center text-xs text-[#5E796F]">
            © {new Date().getFullYear()} Topkolik.com - Taraftar Sosyal Platformu. Tamamen ücretsiz beceri ve tahmin ligi.
            Bahis veya kumar kesinlikle yer almaz.
          </div>
        </footer>
      </body>
    </html>
  );
}
