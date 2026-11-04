"use client";

import { useState } from "react";
import { ShieldAlert, AlertOctagon, Timer, CheckCircle, XCircle, ShieldCheck, Lock } from "lucide-react";

export default function AdminDashboardPage() {
  const [mfaToken, setMfaToken] = useState("mfa-step-up-sample-token");
  const [emergencyLockdown, setEmergencyLockdown] = useState(false);
  const [slowModeSeconds, setSlowModeSeconds] = useState(15);
  const [feedback, setFeedback] = useState<string | null>(null);

  const [reports, setReports] = useState([
    {
      id: 1,
      commentId: 104,
      author: "Bot_User_99",
      body: "Canlı maç izle: http://korsan-yayin.xyz/derbi-hd bedava link!",
      reason: "Korsan Yayın Linki",
      state: "quarantined",
      reportedAt: "5 dk önce",
    },
    {
      id: 2,
      commentId: 105,
      author: "BetKing_Casino",
      body: "500 TL deneme bonusu için tıkla: http://yasadisi-bahis.com",
      reason: "Yasadışı Bahis / Kumar Reklamı",
      state: "quarantined",
      reportedAt: "12 dk önce",
    },
  ]);

  const handleToggleLockdown = () => {
    if (!mfaToken) {
      setFeedback("MFA yetki doğrulaması gereklidir.");
      return;
    }
    const next = !emergencyLockdown;
    setEmergencyLockdown(next);
    setFeedback(
      next
        ? "ACİL DURUM KİLİDİ AKTİF: Tüm arenada mesaj gönderimi donduruldu."
        : "Acil durum kilidi kaldırıldı. Yorum gönderimi normale döndü."
    );
  };

  const handleUpdateSlowMode = (secs: number) => {
    setSlowModeSeconds(secs);
    setFeedback(`Yavaş mod süresi ${secs} saniye olarak güncellendi.`);
  };

  const handleResolveReport = (reportId: number, action: "approve" | "remove") => {
    setReports((prev) => prev.filter((r) => r.id !== reportId));
    setFeedback(
      action === "remove"
        ? `Rapor #${reportId} onaylandı: Yorum silindi ve kullanıcı engellendi.`
        : `Rapor #${reportId} reddedildi: Yorum yayına alındı.`
    );
  };

  return (
    <div className="space-y-6">
      {/* Title & MFA Bar */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-graphite-border pb-4">
        <div>
          <h1 className="text-2xl font-black text-white tracking-tight">Canlı Maç Arenası Operasyon Konsolu</h1>
          <p className="text-xs text-[#9EBAAF] mt-1">
            Galatasaray vs Fenerbahçe Derbisi (Fixture #1) • Canlı Moderasyon & Güvenlik
          </p>
        </div>
        <div className="flex items-center space-x-2 bg-graphite-card border border-graphite-border px-3 py-1.5 rounded-xl">
          <Lock className="w-4 h-4 text-electric" />
          <span className="text-xs font-mono text-[#9EBAAF]">MFA: {mfaToken.slice(0, 14)}...</span>
        </div>
      </div>

      {/* Feedback Banner */}
      {feedback && (
        <div className="p-3.5 rounded-xl bg-pitch-dark/80 border border-electric text-electric text-xs font-bold flex items-center space-x-2">
          <ShieldCheck className="w-4 h-4 shrink-0" />
          <span>{feedback}</span>
        </div>
      )}

      {/* Emergency Control Grid */}
      <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
        {/* Emergency Lockdown */}
        <div className="bg-graphite-card border border-graphite-border rounded-2xl p-6 space-y-4">
          <div className="flex items-center space-x-3 text-red-400">
            <AlertOctagon className="w-6 h-6 shrink-0" />
            <div>
              <h2 className="text-base font-bold text-white">Acil Durum Kilidi (Emergency Lockdown)</h2>
              <p className="text-xs text-[#9EBAAF]">
                Küfür, provokasyon veya spam saldırısı anında tüm tribünlerde yazmayı anında durdurur.
              </p>
            </div>
          </div>

          <div className="pt-2">
            <button
              onClick={handleToggleLockdown}
              className={`w-full py-3 rounded-xl font-black text-sm transition-all flex items-center justify-center space-x-2 shadow-lg ${
                emergencyLockdown
                  ? "bg-red-600 hover:bg-red-700 text-white shadow-red-600/30"
                  : "bg-graphite-surface hover:bg-graphite-border text-white border border-graphite-border"
              }`}
            >
              <AlertOctagon className="w-4 h-4" />
              <span>{emergencyLockdown ? "KİLİDİ KALDIR (YAYINI AÇ)" : "ACİL DURUM KİLİDİNİ BAŞLAT"}</span>
            </button>
          </div>
        </div>

        {/* Slow Mode Rate Limiting */}
        <div className="bg-graphite-card border border-graphite-border rounded-2xl p-6 space-y-4">
          <div className="flex items-center space-x-3 text-electric">
            <Timer className="w-6 h-6 shrink-0" />
            <div>
              <h2 className="text-base font-bold text-white">Yavaş Mod Hız Sınırı (Slow Mode)</h2>
              <p className="text-xs text-[#9EBAAF]">
                Kullanıcıların iki mesaj arasındaki zorunlu bekleme süresini ayarlar.
              </p>
            </div>
          </div>

          <div className="grid grid-cols-4 gap-2 pt-2">
            {[0, 10, 30, 60].map((secs) => (
              <button
                key={secs}
                onClick={() => handleUpdateSlowMode(secs)}
                className={`py-2.5 rounded-xl font-bold text-xs border transition-all ${
                  slowModeSeconds === secs
                    ? "bg-electric text-[#072B1A] border-electric font-black shadow-lg shadow-electric/20"
                    : "bg-graphite-surface text-[#9EBAAF] border-graphite-border hover:border-electric/40"
                }`}
              >
                {secs === 0 ? "Kapalı" : `${secs}s`}
              </button>
            ))}
          </div>
        </div>
      </div>

      {/* Reports & Safety Filter Queue */}
      <div className="bg-graphite-card border border-graphite-border rounded-2xl p-6 space-y-4">
        <div className="flex items-center justify-between">
          <div className="flex items-center space-x-2 text-white">
            <ShieldAlert className="w-5 h-5 text-amber-400" />
            <h2 className="text-base font-bold">Otomatik Karantina ve Şikayet Kuyruğu</h2>
          </div>
          <span className="px-2.5 py-0.5 rounded-full text-xs font-bold bg-amber-500/20 text-amber-300 border border-amber-500/30">
            {reports.length} Bekleyen
          </span>
        </div>

        {reports.length === 0 ? (
          <div className="text-center py-8 text-xs text-[#5E796F]">
            Şu anda bekleyen şikayet veya karantinaya alınmış mesaj bulunmuyor.
          </div>
        ) : (
          <div className="divide-y divide-graphite-border">
            {reports.map((r) => (
              <div key={r.id} className="py-4 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
                <div className="space-y-1 max-w-xl">
                  <div className="flex items-center space-x-2">
                    <span className="text-xs font-black text-white">{r.author}</span>
                    <span className="text-[10px] px-2 py-0.5 rounded bg-red-600/20 text-red-400 font-bold">
                      {r.reason}
                    </span>
                    <span className="text-[11px] text-[#5E796F]">{r.reportedAt}</span>
                  </div>
                  <p className="text-xs text-white/90 bg-graphite-surface border border-graphite-border p-2.5 rounded-lg font-mono">
                    {r.body}
                  </p>
                </div>

                <div className="flex items-center space-x-2 shrink-0">
                  <button
                    onClick={() => handleResolveReport(r.id, "remove")}
                    className="flex items-center space-x-1 px-3 py-1.5 rounded-lg bg-red-600/20 text-red-300 border border-red-500/30 text-xs font-bold hover:bg-red-600/30 transition-colors"
                  >
                    <XCircle className="w-3.5 h-3.5" />
                    <span>Yorumu Kaldır</span>
                  </button>
                  <button
                    onClick={() => handleResolveReport(r.id, "approve")}
                    className="flex items-center space-x-1 px-3 py-1.5 rounded-lg bg-pitch/40 text-electric border border-pitch text-xs font-bold hover:bg-pitch/60 transition-colors"
                  >
                    <CheckCircle className="w-3.5 h-3.5" />
                    <span>Onayla (Yayınla)</span>
                  </button>
                </div>
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  );
}
